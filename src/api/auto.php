<?php
/**
 * 赛博多人股市 - 自动交易策略引擎与接口
 * 服务端定时撮合与策略评估，解决单机版关网页即失效、配置易丢失的问题
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$isDirectRequest = isApiEntryPoint(__FILE__);

/**
 * 自动交易日志保留期清理:
 * 失败记录保留 1 天 (防止重试噪音无限堆积), 成功成交记录保留 30 天
 * 由策略引擎按概率触发 (约每 10 分钟一波), 单条 DELETE 走部分索引, 开销可忽略
 */
function cleanupAutoTradeLogs(PDO $db): void {
    if (mt_rand(1, 240) !== 1) {
        return;
    }
    try {
        $db->exec("DELETE FROM auto_trade_logs WHERE success = FALSE AND created_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
        $db->exec("DELETE FROM auto_trade_logs WHERE success = TRUE AND created_at < CURRENT_TIMESTAMP - INTERVAL '30 days'");
    } catch (Exception $e) {
        error_log('Auto trade logs cleanup error: ' . $e->getMessage());
    }
}

/**
 * 评估并执行全服所有活跃的自动交易策略 (由 market.php 每个 Tick 调用)
 */
function evaluateAutoStrategies(PDO $db): array {
    cleanupAutoTradeLogs($db);

    // 1. 获取全服所有处于激活状态的策略 (行级锁由单个策略的事务处理)
    $stmt = $db->query('
        SELECT s.*, st.name AS stock_name, st.current_price, st.prev_close, 
               st.status AS stock_status, st.liquidity_depth, st.total_shares, st.unlock_required_users
        FROM auto_strategies s
        JOIN stocks st ON s.stock_code = st.code
        WHERE s.is_active = TRUE
        ORDER BY s.id ASC
        LIMIT 100
    ');
    $strategies = $stmt->fetchAll();
    if (empty($strategies)) {
        return ['evaluated' => 0, 'executed' => 0];
    }

    $playerCount = max(1, (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn());
    $totalCapital = (float)$db->query("SELECT COALESCE(SUM(balance + locked_margin), 0) FROM users")->fetchColumn();
    $capitalMultiplier = max(1.0, 1.0 + ($totalCapital - 2000000.0) / 10000000.0);

    $now = time();
    $executed = 0;

    foreach ($strategies as $strat) {
        $stratId = (int)$strat['id'];
        $userId = (int)$strat['user_id'];
        $code = $strat['stock_code'];
        $direction = strtolower($strat['direction']);
        $condType = $strat['condition_type'];
        $condOp = $strat['condition_operator'];
        $condVal = (float)$strat['condition_value'];
        $qty = (int)$strat['quantity'];
        $curPrice = (float)$strat['current_price'];
        $prevClose = (float)$strat['prev_close'];

        // A. 冷却时间校验
        $cooldown = ($condType === 'time') ? AUTO_TIME_COOLDOWN_SECONDS : AUTO_COOLDOWN_SECONDS;
        if (!empty($strat['last_triggered_at'])) {
            $lastTime = strtotime($strat['last_triggered_at']);
            if (($now - $lastTime) < $cooldown) {
                continue;
            }
        }

        // B. 每日触发上限与总触发次数
        if ((int)$strat['triggered_count'] >= (int)$strat['max_trigger_count']) {
            $stmtOff = $db->prepare('UPDATE auto_strategies SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtOff->execute(['id' => $stratId]);
            continue;
        }

        // C. 股票状态校验
        if ($strat['stock_status'] !== 'TRADING') {
            continue;
        }

        // D. 全服解锁人数校验
        $reqUsers = (int)($strat['unlock_required_users'] ?? 1);
        if ($reqUsers > $playerCount) {
            continue;
        }

        // E. 检查用户状态及风控熔断
        $stmtU = $db->prepare('SELECT id, balance, locked_margin, initial_fund, status FROM users WHERE id = :id');
        $stmtU->execute(['id' => $userId]);
        $uRow = $stmtU->fetch();
        if (!$uRow || $uRow['status'] === 'banned') {
            $stmtOff = $db->prepare('UPDATE auto_strategies SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtOff->execute(['id' => $stratId]);
            continue;
        }

        $initFund = (float)$uRow['initial_fund'];
        $availCash = (float)$uRow['balance'];
        $lockedMargin = (float)$uRow['locked_margin'];

        // 全仓风控熔断：可用资金+保证金总和若严重低于初始资金 (低于 50%)，暂停全部策略
        if ($initFund > 0 && ($availCash + $lockedMargin) < ($initFund * AUTO_DRAWDOWN_HALT)) {
            $stmtHalt = $db->prepare('UPDATE auto_strategies SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND is_active = TRUE');
            $stmtHalt->execute(['uid' => $userId]);

            $stmtLog = $db->prepare('
                INSERT INTO auto_trade_logs (strategy_id, user_id, stock_code, direction, quantity, price, success, message)
                VALUES (:sid, :uid, :code, :dir, :q, :p, FALSE, :msg)
            ');
            $stmtLog->execute([
                'sid' => $stratId,
                'uid' => $userId,
                'code' => $code,
                'dir' => strtoupper($direction),
                'q' => $qty,
                'p' => $curPrice,
                'msg' => '账户可用资产跌破 50% 熔断线，已自动暂停全部自动交易策略'
            ]);
            continue;
        }

        // F. 评估触发条件
        $shouldTrade = false;
        $changePercent = $prevClose > 0 ? (($curPrice - $prevClose) / $prevClose * 100.0) : 0.0;

        switch ($condType) {
            case 'price':
                if ($condOp === 'above' && $curPrice > $condVal) $shouldTrade = true;
                elseif ($condOp === 'below' && $curPrice < $condVal) $shouldTrade = true;
                elseif ($condOp === 'equal' && abs($curPrice - $condVal) <= 0.01) $shouldTrade = true;
                break;

            case 'percentage':
                if ($condOp === 'above' && $changePercent > $condVal) $shouldTrade = true;
                elseif ($condOp === 'below' && $changePercent < $condVal) $shouldTrade = true;
                elseif ($condOp === 'equal' && abs($changePercent - $condVal) <= 0.05) $shouldTrade = true;
                break;

            case 'profit':
                if ($direction === 'sell') {
                    $stmtPos = $db->prepare('SELECT quantity, avg_cost FROM positions WHERE user_id = :uid AND stock_code = :c AND position_type = \'LONG\'');
                    $stmtPos->execute(['uid' => $userId, 'c' => $code]);
                    $pos = $stmtPos->fetch();
                    if ($pos && (int)$pos['quantity'] > 0) {
                        $pnl = ($curPrice - (float)$pos['avg_cost']) * (int)$pos['quantity'];
                        if ($condOp === 'above' && $pnl >= $condVal) $shouldTrade = true;
                        elseif ($condOp === 'below' && $pnl <= -$condVal) $shouldTrade = true;
                    }
                }
                break;

            case 'time':
                // 定时时间间隔模式：冷却到期即触发
                $shouldTrade = true;
                break;
        }

        // 附加止损止盈检查 (卖出操作)
        if ($direction === 'sell' && !$shouldTrade) {
            $stmtPos = $db->prepare('SELECT quantity, avg_cost FROM positions WHERE user_id = :uid AND stock_code = :c AND position_type = \'LONG\'');
            $stmtPos->execute(['uid' => $userId, 'c' => $code]);
            $pos = $stmtPos->fetch();
            if ($pos && (int)$pos['quantity'] > 0) {
                $pnl = ($curPrice - (float)$pos['avg_cost']) * (int)$pos['quantity'];
                $stopLoss = (float)$strat['stop_loss'];
                $takeProfit = (float)$strat['take_profit'];

                if ($stopLoss > 0 && $pnl <= -abs($stopLoss)) {
                    $shouldTrade = true;
                } elseif ($takeProfit > 0 && $pnl >= abs($takeProfit)) {
                    $shouldTrade = true;
                }
            }
        }

        if (!$shouldTrade) {
            continue;
        }

        // G. 执行策略交易 (使用 PostgreSQL SAVEPOINT 隔离单个策略执行异常)
        $spName = 'sp_auto_' . $stratId;
        $db->exec("SAVEPOINT {$spName}");

        try {
            $res = executeAutoTradeSingle($db, $strat, $capitalMultiplier);
            $db->exec("RELEASE SAVEPOINT {$spName}");
            if ($res['success']) {
                $executed++;
            }
        } catch (Exception $e) {
            $db->exec("ROLLBACK TO SAVEPOINT {$spName}");
            error_log("Auto-trade strategy #{$stratId} error: " . $e->getMessage());

            // 记录失败日志
            $stmtLog = $db->prepare('
                INSERT INTO auto_trade_logs (strategy_id, user_id, stock_code, direction, quantity, price, success, message)
                VALUES (:sid, :uid, :code, :dir, :q, :p, FALSE, :msg)
            ');
            $stmtLog->execute([
                'sid' => $stratId,
                'uid' => $userId,
                'code' => $code,
                'dir' => strtoupper($direction),
                'q' => $qty,
                'p' => $curPrice,
                'msg' => mb_substr('执行异常: ' . $e->getMessage(), 0, 190)
            ]);
        }
    }

    return ['evaluated' => count($strategies), 'executed' => $executed];
}

/**
 * 执行单笔自动交易 (买入或卖出)
 */
function executeAutoTradeSingle(PDO $db, array $strat, float $capitalMultiplier): array {
    $stratId = (int)$strat['id'];
    $userId = (int)$strat['user_id'];
    $code = $strat['stock_code'];
    $direction = strtolower($strat['direction']);
    $qty = (int)$strat['quantity'];
    $priceType = strtoupper($strat['price_type'] ?? 'MARKET');
    $limitPrice = (float)($strat['limit_price'] ?? 0);
    $maxAmount = (float)($strat['max_amount'] ?? 0);

    // 重新锁股票最新数据
    $stmtSt = $db->prepare('SELECT * FROM stocks WHERE code = :c FOR UPDATE');
    $stmtSt->execute(['c' => $code]);
    $stock = $stmtSt->fetch();
    if (!$stock || $stock['status'] !== 'TRADING') {
        return ['success' => false, 'message' => '股票不可交易'];
    }

    $curPrice = (float)$stock['current_price'];
    $prevClose = (float)$stock['prev_close'];
    $depth = max(1000, (int)$stock['liquidity_depth']);

    // 限价校验
    if ($priceType === 'LIMIT' && $limitPrice > 0) {
        if ($direction === 'buy' && $curPrice > $limitPrice) {
            return ['success' => false, 'message' => '现价高于买入限价'];
        }
        if ($direction === 'sell' && $curPrice < $limitPrice) {
            return ['success' => false, 'message' => '现价低于卖出限价'];
        }
    }

    // 最大金额控制 (动态削减买入股数)
    if ($direction === 'buy' && $maxAmount > 0 && ($curPrice * $qty) > $maxAmount) {
        $qty = (int)floor($maxAmount / max(0.01, $curPrice));
        if ($qty <= 0) {
            return ['success' => false, 'message' => '受最大交易金额限制，可买数量为0'];
        }
    }

    $tradeAmount = round($curPrice * $qty, 2);
    $reqId = "auto-{$stratId}-" . ((int)$strat['triggered_count'] + 1);

    if ($direction === 'buy') {
        // 1. 流通盘锁仓检查
        $totalShares = (int)(($stock['total_shares'] ?? 200000) * $capitalMultiplier);
        $stmtHeld = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :c AND position_type = 'LONG'");
        $stmtHeld->execute(['c' => $code]);
        $totalHeldLong = (int)$stmtHeld->fetchColumn();

        $availShares = max(0, $totalShares - $totalHeldLong);
        if ($qty > $availShares) {
            recordAutoTradeLog($db, $stratId, $userId, $code, 'BUY', $qty, $curPrice, false, "流通盘不足 (余 {$availShares} 股)");
            return ['success' => false, 'message' => '流通盘已被锁仓'];
        }

        // 2. 资金校验
        $fee = BUY_FEE_RATE > 0 ? max(5.0, round($tradeAmount * BUY_FEE_RATE, 2)) : 0.0;
        $totalCost = round($tradeAmount + $fee, 2);

        $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
        $stmtU->execute(['id' => $userId]);
        $u = $stmtU->fetch();
        $balance = (float)$u['balance'];

        if ($balance < $totalCost) {
            recordAutoTradeLog($db, $stratId, $userId, $code, 'BUY', $qty, $curPrice, false, "可用资金不足 (需 ¥" . number_format($totalCost, 2) . ", 可用 ¥" . number_format($balance, 2) . ")");
            return ['success' => false, 'message' => '资金不足'];
        }

        $newBal = round($balance - $totalCost, 4);
        $stmtUpU = $db->prepare('UPDATE users SET balance = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmtUpU->execute(['b' => $newBal, 'id' => $userId]);

        // 3. 多头持仓更新
        $stmtPos = $db->prepare('SELECT id, quantity, available_quantity, avg_cost FROM positions WHERE user_id = :uid AND stock_code = :c AND position_type = \'LONG\' FOR UPDATE');
        $stmtPos->execute(['uid' => $userId, 'c' => $code]);
        $pos = $stmtPos->fetch();

        if ($pos) {
            $oldQ = (int)$pos['quantity'];
            $oldCost = (float)$pos['avg_cost'];
            $newQ = $oldQ + $qty;
            $newCost = round(($oldQ * $oldCost + $tradeAmount) / $newQ, 4);

            $stmtUpP = $db->prepare('UPDATE positions SET quantity = :q, available_quantity = available_quantity + :aq, avg_cost = :c, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUpP->execute(['q' => $newQ, 'aq' => $qty, 'c' => $newCost, 'id' => $pos['id']]);
        } else {
            $stmtInsP = $db->prepare('INSERT INTO positions (user_id, stock_code, position_type, quantity, available_quantity, avg_cost, locked_margin) VALUES (:uid, :c, \'LONG\', :q, :aq, :cst, 0)');
            $stmtInsP->execute(['uid' => $userId, 'c' => $code, 'q' => $qty, 'aq' => $qty, 'cst' => $curPrice]);
        }

        // 4. 订单与成交
        $stmtOrd = $db->prepare('INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id) VALUES (:uid, :c, \'BUY\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id');
        $stmtOrd->execute(['uid' => $userId, 'c' => $code, 'p' => $curPrice, 'q' => $qty, 'fq' => $qty, 'req' => $reqId]);
        $ordId = $stmtOrd->fetchColumn();

        $stmtTr = $db->prepare('INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl) VALUES (:oid, :uid, :c, \'BUY\', :p, :q, :amt, :fee, 0) RETURNING id');
        $stmtTr->execute(['oid' => $ordId, 'uid' => $userId, 'c' => $code, 'p' => $curPrice, 'q' => $qty, 'amt' => $tradeAmount, 'fee' => $fee]);
        $trId = $stmtTr->fetchColumn();

        // 5. 记账流水
        $stmtL = $db->prepare('INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description) VALUES (:uid, \'BUY_STOCK\', :amt, :bal, :lm, \'AUTO_TRADE\', :ref, :desc)');
        $stmtL->execute([
            'uid' => $userId,
            'amt' => -$totalCost,
            'bal' => $newBal,
            'lm' => (float)$u['locked_margin'],
            'ref' => (string)$trId,
            'desc' => sprintf('自动策略 #%d 买入 %s (%s) %d 股 @ ¥%s', $stratId, $stock['name'], $code, $qty, $curPrice)
        ]);

        // 6. 价格冲击与 K 线
        $pressure = min(0.09, ($qty / $depth) * MARKET_SENSITIVITY);
        $finalPrice = max(round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2), min(round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2), round($curPrice * (1.0 + $pressure), 2)));

        $stmtUpS = $db->prepare('UPDATE stocks SET current_price = :p, high_price = GREATEST(high_price, :p), low_price = LEAST(low_price, :p), volume = volume + :v, amount = amount + :amt, updated_at = CURRENT_TIMESTAMP WHERE code = :c');
        $stmtUpS->execute(['p' => $finalPrice, 'v' => $qty, 'amt' => $tradeAmount, 'c' => $code]);

        // 7. 更新策略状态
        updateStrategyTriggerState($db, $stratId, (int)$strat['triggered_count'] + 1, (int)$strat['max_trigger_count']);

        // 8. 记录日志
        recordAutoTradeLog($db, $stratId, $userId, $code, 'BUY', $qty, $curPrice, true, sprintf('自动买入成功，花费 ¥%s (新价 ¥%s)', number_format($totalCost, 2), number_format($finalPrice, 2)), 0);

        return ['success' => true, 'trade_id' => $trId];

    } else { // SELL
        // 1. 持仓校验
        $stmtPos = $db->prepare('SELECT id, quantity, available_quantity, avg_cost FROM positions WHERE user_id = :uid AND stock_code = :c AND position_type = \'LONG\' FOR UPDATE');
        $stmtPos->execute(['uid' => $userId, 'c' => $code]);
        $pos = $stmtPos->fetch();

        if (!$pos || (int)$pos['quantity'] < $qty) {
            $curHold = $pos ? (int)$pos['quantity'] : 0;
            recordAutoTradeLog($db, $stratId, $userId, $code, 'SELL', $qty, $curPrice, false, "持仓不足 (持仓 {$curHold} 股, 拟卖出 {$qty} 股)");
            return ['success' => false, 'message' => '持仓不足'];
        }

        $avgCost = (float)$pos['avg_cost'];
        $fee = SELL_FEE_RATE > 0 ? max(5.0, round($tradeAmount * SELL_FEE_RATE, 2)) : 0.0;
        $netIncome = round($tradeAmount - $fee, 2);
        $realizedPnl = round(($curPrice - $avgCost) * $qty - $fee, 2);

        // 2. 更新持仓
        $remQ = (int)$pos['quantity'] - $qty;
        $remAvailQ = max(0, (int)$pos['available_quantity'] - $qty);
        if ($remQ > 0) {
            $stmtUpP = $db->prepare('UPDATE positions SET quantity = :q, available_quantity = :aq, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUpP->execute(['q' => $remQ, 'aq' => $remAvailQ, 'id' => $pos['id']]);
        } else {
            $stmtDelP = $db->prepare('DELETE FROM positions WHERE id = :id');
            $stmtDelP->execute(['id' => $pos['id']]);
        }

        // 3. 增加用户余额
        $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
        $stmtU->execute(['id' => $userId]);
        $u = $stmtU->fetch();
        $newBal = round((float)$u['balance'] + $netIncome, 4);

        $stmtUpU = $db->prepare('UPDATE users SET balance = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmtUpU->execute(['b' => $newBal, 'id' => $userId]);

        // 4. 订单与成交
        $stmtOrd = $db->prepare('INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id) VALUES (:uid, :c, \'SELL\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id');
        $stmtOrd->execute(['uid' => $userId, 'c' => $code, 'p' => $curPrice, 'q' => $qty, 'fq' => $qty, 'req' => $reqId]);
        $ordId = $stmtOrd->fetchColumn();

        $stmtTr = $db->prepare('INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl) VALUES (:oid, :uid, :c, \'SELL\', :p, :q, :amt, :fee, :pnl) RETURNING id');
        $stmtTr->execute(['oid' => $ordId, 'uid' => $userId, 'c' => $code, 'p' => $curPrice, 'q' => $qty, 'amt' => $tradeAmount, 'fee' => $fee, 'pnl' => $realizedPnl]);
        $trId = $stmtTr->fetchColumn();

        // 5. 记账流水
        $stmtL = $db->prepare('INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description) VALUES (:uid, \'SELL_STOCK\', :amt, :bal, :lm, \'AUTO_TRADE\', :ref, :desc)');
        $stmtL->execute([
            'uid' => $userId,
            'amt' => $netIncome,
            'bal' => $newBal,
            'lm' => (float)$u['locked_margin'],
            'ref' => (string)$trId,
            'desc' => sprintf('自动策略 #%d 卖出 %s (%s) %d 股 @ ¥%s，实现盈亏 ¥%s', $stratId, $stock['name'], $code, $qty, $curPrice, $realizedPnl)
        ]);

        // 6. 价格冲击 (卖出砸盘)
        $pressure = min(0.09, ($qty / $depth) * MARKET_SENSITIVITY);
        $finalPrice = max(round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2), min(round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2), round($curPrice * (1.0 - $pressure), 2)));

        $stmtUpS = $db->prepare('UPDATE stocks SET current_price = :p, high_price = GREATEST(high_price, :p), low_price = LEAST(low_price, :p), volume = volume + :v, amount = amount + :amt, updated_at = CURRENT_TIMESTAMP WHERE code = :c');
        $stmtUpS->execute(['p' => $finalPrice, 'v' => $qty, 'amt' => $tradeAmount, 'c' => $code]);

        // 7. 更新策略状态
        updateStrategyTriggerState($db, $stratId, (int)$strat['triggered_count'] + 1, (int)$strat['max_trigger_count']);

        // 8. 记录日志
        recordAutoTradeLog($db, $stratId, $userId, $code, 'SELL', $qty, $curPrice, true, sprintf('自动卖出成功，实现盈亏 ¥%s，到账 ¥%s', number_format($realizedPnl, 2), number_format($netIncome, 2)), $realizedPnl);

        return ['success' => true, 'trade_id' => $trId];
    }
}

function updateStrategyTriggerState(PDO $db, int $stratId, int $newCount, int $maxCount): void {
    $isActive = ($newCount < $maxCount);
    $stmt = $db->prepare('
        UPDATE auto_strategies SET 
            triggered_count = :cnt,
            is_active = :act,
            last_triggered_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ');
    $stmt->execute(['cnt' => $newCount, 'act' => $isActive ? 'true' : 'false', 'id' => $stratId]);
}

function recordAutoTradeLog(PDO $db, int $stratId, int $userId, string $code, string $direction, int $qty, float $price, bool $success, string $msg, float $pnl = 0.0): void {
    $stmt = $db->prepare('
        INSERT INTO auto_trade_logs (strategy_id, user_id, stock_code, direction, quantity, price, success, message, pnl)
        VALUES (:sid, :uid, :code, :dir, :q, :p, :succ, :msg, :pnl)
    ');
    $stmt->execute([
        'sid' => $stratId,
        'uid' => $userId,
        'code' => $code,
        'dir' => $direction,
        'q' => $qty,
        'p' => $price,
        'succ' => $success ? 'true' : 'false',
        'msg' => mb_substr($msg, 0, 190),
        'pnl' => $pnl
    ]);
}

// ==============================================================================
// HTTP 路由处理 (仅直接访问 auto.php 时执行)
// ==============================================================================
if ($isDirectRequest) {
    $user = requireAuth();
    $db = DB::getConnection();
    $action = $_GET['action'] ?? 'list';
    $input = getJsonInput();

    switch ($action) {
        case 'list':
            $stmt = $db->prepare('
                SELECT s.*, st.name AS stock_name, st.current_price, st.prev_close,
                       TO_CHAR(s.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS created_time,
                       TO_CHAR(s.last_triggered_at, \'YYYY-MM-DD HH24:MI:SS\') AS last_time
                FROM auto_strategies s
                JOIN stocks st ON s.stock_code = st.code
                WHERE s.user_id = :uid
                ORDER BY s.id DESC
            ');
            $stmt->execute(['uid' => $user['id']]);
            $rows = $stmt->fetchAll();

            $strategies = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'stock_code' => $r['stock_code'],
                    'stock_name' => $r['stock_name'],
                    'name' => $r['name'],
                    'direction' => $r['direction'],
                    'condition_type' => $r['condition_type'],
                    'condition_operator' => $r['condition_operator'],
                    'condition_value' => (float)$r['condition_value'],
                    'quantity' => (int)$r['quantity'],
                    'price_type' => $r['price_type'],
                    'limit_price' => (float)$r['limit_price'],
                    'stop_loss' => (float)$r['stop_loss'],
                    'take_profit' => (float)$r['take_profit'],
                    'max_amount' => (float)$r['max_amount'],
                    'is_active' => filter_var($r['is_active'], FILTER_VALIDATE_BOOLEAN),
                    'max_trigger_count' => (int)$r['max_trigger_count'],
                    'triggered_count' => (int)$r['triggered_count'],
                    'last_triggered_at' => $r['last_time'],
                    'created_at' => $r['created_time'],
                    'current_price' => (float)$r['current_price'],
                    'prev_close' => (float)$r['prev_close']
                ];
            }, $rows);

            $activeCount = count(array_filter($strategies, fn($s) => $s['is_active']));

            jsonResponse([
                'strategies' => $strategies,
                'active_count' => $activeCount,
                'risk_config' => [
                    'max_strategies' => AUTO_MAX_STRATEGIES,
                    'cooldown_seconds' => AUTO_COOLDOWN_SECONDS,
                    'time_cooldown_seconds' => AUTO_TIME_COOLDOWN_SECONDS,
                    'drawdown_halt_ratio' => AUTO_DRAWDOWN_HALT
                ]
            ]);
            break;

        case 'create':
            $stockCode = trim($input['stock_code'] ?? '');
            $direction = strtolower(trim($input['direction'] ?? 'buy'));
            $condType = trim($input['condition_type'] ?? 'price');
            $condOp = trim($input['condition_operator'] ?? 'above');
            $condVal = (float)($input['condition_value'] ?? 0.0);
            $qty = (int)($input['quantity'] ?? 0);
            $priceType = strtoupper(trim($input['price_type'] ?? 'MARKET'));
            $limitPrice = (float)($input['limit_price'] ?? 0.0);
            $stopLoss = max(0.0, (float)($input['stop_loss'] ?? 0.0));
            $takeProfit = max(0.0, (float)($input['take_profit'] ?? 0.0));
            $maxAmount = max(0.0, (float)($input['max_amount'] ?? 0.0));
            $maxTriggers = max(1, min(100, (int)($input['max_trigger_count'] ?? 1)));
            $name = trim($input['name'] ?? '');

            if (empty($stockCode) || $qty <= 0) {
                errorResponse('请提供有效的股票代码与数量 (正整数)');
            }
            if (!in_array($direction, ['buy', 'sell'])) {
                errorResponse('交易方向只能为 buy 或 sell');
            }
            if (!in_array($condType, ['price', 'percentage', 'profit', 'time'])) {
                errorResponse('无效的触发条件类型');
            }
            if (!in_array($condOp, ['above', 'below', 'equal'])) {
                errorResponse('无效的条件比较符');
            }
            if (!in_array($priceType, ['MARKET', 'LIMIT'])) {
                errorResponse('价格类型只能为 MARKET 或 LIMIT');
            }

            // 检查活跃策略上限
            $stmtCnt = $db->prepare('SELECT COUNT(*) FROM auto_strategies WHERE user_id = :uid AND is_active = TRUE');
            $stmtCnt->execute(['uid' => $user['id']]);
            if ((int)$stmtCnt->fetchColumn() >= AUTO_MAX_STRATEGIES) {
                errorResponse('活跃策略数已达上限 (' . AUTO_MAX_STRATEGIES . ' 个)，请先暂停或删除已有策略', 400);
            }

            // 校验股票存在
            $stmtSt = $db->prepare('SELECT code, name FROM stocks WHERE code = :c');
            $stmtSt->execute(['c' => $stockCode]);
            $stRow = $stmtSt->fetch();
            if (!$stRow) {
                errorResponse('指定的股票不存在');
            }
            if (empty($name)) {
                $name = $stRow['name'];
            }

            $stmtIns = $db->prepare('
                INSERT INTO auto_strategies (
                    user_id, stock_code, name, direction, condition_type, condition_operator,
                    condition_value, quantity, price_type, limit_price, stop_loss, take_profit,
                    max_amount, is_active, max_trigger_count, triggered_count
                ) VALUES (
                    :uid, :code, :name, :dir, :ctype, :cop,
                    :cval, :q, :ptype, :lprice, :sloss, :tprofit,
                    :maxamt, TRUE, :maxtrig, 0
                ) RETURNING id
            ');
            $stmtIns->execute([
                'uid' => $user['id'],
                'code' => $stockCode,
                'name' => mb_substr($name, 0, 40),
                'dir' => $direction,
                'ctype' => $condType,
                'cop' => $condOp,
                'cval' => $condVal,
                'q' => $qty,
                'ptype' => $priceType,
                'lprice' => $limitPrice,
                'sloss' => $stopLoss,
                'tprofit' => $takeProfit,
                'maxamt' => $maxAmount,
                'maxtrig' => $maxTriggers
            ]);
            $newId = $stmtIns->fetchColumn();

            jsonResponse(['strategy_id' => (int)$newId], '自动交易策略已添加并生效');
            break;

        case 'toggle':
            $id = (int)($input['id'] ?? 0);
            $isActive = filter_var($input['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($id <= 0) {
                errorResponse('请提供策略 ID');
            }

            if ($isActive) {
                $stmtCnt = $db->prepare('SELECT COUNT(*) FROM auto_strategies WHERE user_id = :uid AND is_active = TRUE');
                $stmtCnt->execute(['uid' => $user['id']]);
                if ((int)$stmtCnt->fetchColumn() >= AUTO_MAX_STRATEGIES) {
                    errorResponse('活跃策略数已达上限 (' . AUTO_MAX_STRATEGIES . ' 个)，无法启动新策略', 400);
                }
            }

            $stmtUp = $db->prepare('UPDATE auto_strategies SET is_active = :act, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :uid');
            $stmtUp->execute(['act' => $isActive ? 'true' : 'false', 'id' => $id, 'uid' => $user['id']]);

            jsonResponse(null, $isActive ? '策略已启动' : '策略已暂停');
            break;

        case 'delete':
            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                errorResponse('请提供策略 ID');
            }

            $stmtDel = $db->prepare('DELETE FROM auto_strategies WHERE id = :id AND user_id = :uid');
            $stmtDel->execute(['id' => $id, 'uid' => $user['id']]);

            jsonResponse(null, '策略已删除');
            break;

        case 'records':
            $limit = max(5, min(AUTO_MAX_LOGS_RETURN, (int)($_GET['limit'] ?? 30)));
            $stratId = (int)($_GET['strategy_id'] ?? 0);

            if ($stratId > 0) {
                $stmt = $db->prepare('
                    SELECT l.*, s.name AS stock_name,
                           TO_CHAR(l.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS time_str
                    FROM auto_trade_logs l
                    LEFT JOIN stocks s ON l.stock_code = s.code
                    WHERE l.user_id = :uid AND l.strategy_id = :sid
                    ORDER BY l.created_at DESC
                    LIMIT :limit
                ');
                $stmt->bindValue(':uid', $user['id'], PDO::PARAM_INT);
                $stmt->bindValue(':sid', $stratId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $db->prepare('
                    SELECT l.*, s.name AS stock_name,
                           TO_CHAR(l.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS time_str
                    FROM auto_trade_logs l
                    LEFT JOIN stocks s ON l.stock_code = s.code
                    WHERE l.user_id = :uid
                    ORDER BY l.created_at DESC
                    LIMIT :limit
                ');
                $stmt->bindValue(':uid', $user['id'], PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
            }

            $rows = $stmt->fetchAll();
            $records = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'strategy_id' => (int)$r['strategy_id'],
                    'stock_code' => $r['stock_code'],
                    'stock_name' => $r['stock_name'] ?? $r['stock_code'],
                    'direction' => $r['direction'],
                    'quantity' => (int)$r['quantity'],
                    'price' => (float)$r['price'],
                    'success' => filter_var($r['success'], FILTER_VALIDATE_BOOLEAN),
                    'message' => $r['message'],
                    'pnl' => (float)$r['pnl'],
                    'time' => $r['time_str']
                ];
            }, $rows);

            // 统计总自动交易盈亏与执行次数
            $stmtStat = $db->prepare('SELECT COUNT(*) AS total_count, COALESCE(SUM(pnl), 0) AS total_pnl FROM auto_trade_logs WHERE user_id = :uid AND success = TRUE');
            $stmtStat->execute(['uid' => $user['id']]);
            $stat = $stmtStat->fetch();

            jsonResponse([
                'records' => $records,
                'stats' => [
                    'total_count' => (int)$stat['total_count'],
                    'total_pnl' => (float)$stat['total_pnl']
                ]
            ]);
            break;

        case 'clear_records':
            $stmt = $db->prepare('DELETE FROM auto_trade_logs WHERE user_id = :uid');
            $stmt->execute(['uid' => $user['id']]);
            jsonResponse(null, '自动交易历史记录已清空');
            break;

        case 'reset_config':
            $stmt = $db->prepare('DELETE FROM auto_strategies WHERE user_id = :uid');
            $stmt->execute(['uid' => $user['id']]);
            jsonResponse(null, '自动交易策略配置已重置');
            break;

        default:
            errorResponse('未知的自动交易操作', 404);
            break;
    }
}
