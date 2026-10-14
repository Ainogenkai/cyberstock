<?php
/**
 * 赛博多人股市 - 现货股票买入与卖出交易接口
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$action = $_GET['action'] ?? '';
$user = requireAuth();
$db = DB::getConnection();
$input = getJsonInput();

switch ($action) {
    case 'buy':
        $stockCode = trim($input['stock_code'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        $clientReqId = trim($input['client_req_id'] ?? '');

        if (empty($stockCode) || $quantity <= 0) {
            errorResponse('请输入合法的股票代码和买入数量 (正整数)');
        }
        // 数量规则：任意正整数股数均可下单 (兼容前端 1/4、1/2 等仓位快捷键计算的非整手数量)

        DB::beginTransaction();
        try {
            // 1. 检查幂等性
            if (!empty($clientReqId)) {
                $stmtReq = $db->prepare('SELECT id FROM orders WHERE client_req_id = :req AND user_id = :uid');
                $stmtReq->execute(['req' => $clientReqId, 'uid' => $user['id']]);
                if ($stmtReq->fetch()) {
                    DB::rollBack();
                    errorResponse('请勿重复提交同一笔订单 (幂等拦截)');
                }
            }

            // 2. 锁定并获取股票信息
            $stmtStock = $db->prepare('SELECT * FROM stocks WHERE code = :code FOR UPDATE');
            $stmtStock->execute(['code' => $stockCode]);
            $stock = $stmtStock->fetch();

            if (!$stock) {
                DB::rollBack();
                errorResponse('股票不存在: ' . htmlspecialchars($stockCode));
            }
            if ($stock['status'] === 'CIRCUIT_BREAKER') {
                DB::rollBack();
                errorResponse('该股票当前处于熔断冷静期，暂停交易');
            }
            if ($stock['status'] === 'HALTED') {
                DB::rollBack();
                errorResponse('该股票当前已停牌');
            }

            $currentPrice = (float)$stock['current_price'];
            $prevClose = (float)$stock['prev_close'];
            $depth = max(1000, (int)$stock['liquidity_depth']);

            // 2.3 阶梯解锁校验：检查全服玩家人数是否达到解禁门槛
            $playerCount = max(1, (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn());
            $requiredUsers = (int)($stock['unlock_required_users'] ?? 1);
            if ($requiredUsers > $playerCount) {
                DB::rollBack();
                errorResponse(sprintf('【尚未解禁】当前全服玩家仅 %d 人，该股票需全服满 %d 人方可解禁交易！快叫群友注册进场解锁！', $playerCount, $requiredUsers), 400);
            }

            // 2.5 严格流通盘校验：流通盘随全服所有人总资金增长动态扩容 (高送转机制)
            $totalCapital = (float)$db->query("SELECT COALESCE(SUM(balance + locked_margin), 0) FROM users")->fetchColumn();
            $capitalMultiplier = max(1.0, 1.0 + ($totalCapital - 2000000.0) / 10000000.0);
            $totalShares = (int)(($stock['total_shares'] ?? 200000) * $capitalMultiplier);
            $stmtHeld = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :code AND position_type = 'LONG'");
            $stmtHeld->execute(['code' => $stockCode]);
            $totalHeldLong = (int)$stmtHeld->fetchColumn();

            $availableShares = max(0, $totalShares - $totalHeldLong);
            if ($quantity > $availableShares) {
                DB::rollBack();
                errorResponse(sprintf('【流通盘已被锁仓】%s(%s) 全服总流通盘为 %s 股，已被群友锁仓 %s 股，剩余流通股仅剩 %s 股！无法买入 %d 股。',
                    $stock['name'], $stockCode, number_format($totalShares), number_format($totalHeldLong), number_format($availableShares), $quantity), 400, [
                    'available_shares' => $availableShares,
                    'total_shares' => $totalShares,
                    'held_shares' => $totalHeldLong
                ]);
            }

            // 3. 计算买入费用 (零佣金免税模式)
            $tradeAmount = round($currentPrice * $quantity, 2);
            $fee = BUY_FEE_RATE > 0 ? max(5.0, round($tradeAmount * BUY_FEE_RATE, 2)) : 0.0;
            $totalCost = round($tradeAmount + $fee, 2);

            // 4. 锁定用户资金行
            $stmtUser = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
            $stmtUser->execute(['id' => $user['id']]);
            $userBal = $stmtUser->fetch();
            $balance = (float)$userBal['balance'];

            if ($balance < $totalCost) {
                DB::rollBack();
                errorResponse(sprintf('可用资金不足。需要: ¥%s (含手续费 ¥%s)，当前可用: ¥%s', 
                    number_format($totalCost, 2), number_format($fee, 2), number_format($balance, 2)));
            }

            // 5. 扣减用户资金
            $newBalance = round($balance - $totalCost, 4);
            $stmtUpBal = $db->prepare('UPDATE users SET balance = :bal, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUpBal->execute(['bal' => $newBalance, 'id' => $user['id']]);

            // 6. 更新/创建多头持仓 (加权平均成本)
            $stmtPos = $db->prepare('
                SELECT id, quantity, available_quantity, avg_cost 
                FROM positions 
                WHERE user_id = :uid AND stock_code = :code AND position_type = \'LONG\' 
                FOR UPDATE
            ');
            $stmtPos->execute(['uid' => $user['id'], 'code' => $stockCode]);
            $pos = $stmtPos->fetch();

            if ($pos) {
                $oldQty = (int)$pos['quantity'];
                $oldCost = (float)$pos['avg_cost'];
                $newQty = $oldQty + $quantity;
                $newCost = round(($oldQty * $oldCost + $tradeAmount) / $newQty, 4);

                $stmtUpPos = $db->prepare('
                    UPDATE positions SET 
                        quantity = :q, 
                        available_quantity = available_quantity + :aq, 
                        avg_cost = :c, 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = :id
                ');
                $stmtUpPos->execute(['q' => $newQty, 'aq' => $quantity, 'c' => $newCost, 'id' => $pos['id']]);
            } else {
                $newQty = $quantity;
                $newCost = round($tradeAmount / $quantity, 4);
                $stmtInsPos = $db->prepare('
                    INSERT INTO positions (user_id, stock_code, position_type, quantity, available_quantity, avg_cost, locked_margin)
                    VALUES (:uid, :code, \'LONG\', :q, :aq, :c, 0)
                ');
                $stmtInsPos->execute(['uid' => $user['id'], 'code' => $stockCode, 'q' => $newQty, 'aq' => $quantity, 'c' => $newCost]);
            }

            // 7. 写入订单与成交记录
            $stmtOrder = $db->prepare('
                INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id)
                VALUES (:uid, :code, \'BUY\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id
            ');
            $stmtOrder->execute([
                'uid' => $user['id'],
                'code' => $stockCode,
                'p' => $currentPrice,
                'q' => $quantity,
                'fq' => $quantity,
                'req' => $clientReqId ?: null
            ]);
            $orderId = $stmtOrder->fetchColumn();

            $stmtTrade = $db->prepare('
                INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl)
                VALUES (:oid, :uid, :code, \'BUY\', :p, :q, :amt, :fee, 0) RETURNING id
            ');
            $stmtTrade->execute([
                'oid' => $orderId,
                'uid' => $user['id'],
                'code' => $stockCode,
                'p' => $currentPrice,
                'q' => $quantity,
                'amt' => $tradeAmount,
                'fee' => $fee
            ]);
            $tradeId = $stmtTrade->fetchColumn();

            // 8. 写入资金流水
            $stmtLedger = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                VALUES (:uid, \'BUY_STOCK\', :amt, :bal_after, :lm, \'TRADE\', :ref, :desc)
            ');
            $stmtLedger->execute([
                'uid' => $user['id'],
                'amt' => -$totalCost,
                'bal_after' => $newBalance,
                'lm' => (float)$userBal['locked_margin'],
                'ref' => (string)$tradeId,
                'desc' => sprintf('买入 %s (%s) %d 股 @ ¥%s，手续费 ¥%s', $stock['name'], $stockCode, $quantity, $currentPrice, $fee)
            ]);

            // 9. 订单对价格的影响 (买入推高价格模型)
            $pressure = min(0.09, ($quantity / $depth) * MARKET_SENSITIVITY);
            $impactPrice = round($currentPrice * (1.0 + $pressure), 2);
            $limitUp = round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2);
            $limitDown = round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2);
            $finalPrice = max($limitDown, min($limitUp, $impactPrice));

            $stmtUpStock = $db->prepare('
                UPDATE stocks SET 
                    current_price = :p,
                    high_price = GREATEST(high_price, :p),
                    low_price = LEAST(low_price, :p),
                    volume = volume + :vol,
                    amount = amount + :amt,
                    updated_at = CURRENT_TIMESTAMP
                WHERE code = :code
            ');
            $stmtUpStock->execute([
                'p' => $finalPrice,
                'vol' => $quantity,
                'amt' => $tradeAmount,
                'code' => $stockCode
            ]);

            // 10. 记录 K 线更新
            $minBucket = date('Y-m-d H:i:00');
            $stmtCandle = $db->prepare('
                INSERT INTO candles (stock_code, period, candle_time, open, high, low, close, volume, amount)
                VALUES (:code, \'1m\', :time, :p, :p, :p, :p, :vol, :amt)
                ON CONFLICT (stock_code, period, candle_time) DO UPDATE SET
                    high = GREATEST(candles.high, EXCLUDED.high),
                    low = LEAST(candles.low, EXCLUDED.low),
                    close = EXCLUDED.close,
                    volume = candles.volume + EXCLUDED.volume,
                    amount = candles.amount + EXCLUDED.amount
            ');
            $stmtCandle->execute([
                'code' => $stockCode,
                'time' => $minBucket,
                'p' => $finalPrice,
                'vol' => $quantity,
                'amt' => $tradeAmount
            ]);

            DB::commit();

            jsonResponse([
                'trade_id' => (int)$tradeId,
                'stock_code' => $stockCode,
                'stock_name' => $stock['name'],
                'direction' => 'BUY',
                'executed_price' => $currentPrice,
                'new_market_price' => $finalPrice,
                'quantity' => $quantity,
                'trade_amount' => $tradeAmount,
                'fee' => $fee,
                'balance' => $newBalance,
                'position_quantity' => $newQty,
                'avg_cost' => $newCost
            ], sprintf('买入成功！以 ¥%s 成交 %d 股，花费 ¥%s', number_format($currentPrice, 2), $quantity, number_format($totalCost, 2)));

        } catch (Exception $e) {
            DB::rollBack();
            error_log('Buy error: ' . $e->getMessage());
            errorResponse('买入交易失败，请稍后重试或联系管理员', 500);
        }
        break;

    case 'sell':
        $stockCode = trim($input['stock_code'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        $clientReqId = trim($input['client_req_id'] ?? '');

        if (empty($stockCode) || $quantity <= 0) {
            errorResponse('请输入合法的股票代码和卖出数量 (正整数)');
        }

        DB::beginTransaction();
        try {
            // 1. 检查幂等性
            if (!empty($clientReqId)) {
                $stmtReq = $db->prepare('SELECT id FROM orders WHERE client_req_id = :req AND user_id = :uid');
                $stmtReq->execute(['req' => $clientReqId, 'uid' => $user['id']]);
                if ($stmtReq->fetch()) {
                    DB::rollBack();
                    errorResponse('请勿重复提交同一笔订单 (幂等拦截)');
                }
            }

            // 2. 锁定并获取用户多头持仓
            $stmtPos = $db->prepare('
                SELECT id, quantity, available_quantity, avg_cost 
                FROM positions 
                WHERE user_id = :uid AND stock_code = :code AND position_type = \'LONG\' 
                FOR UPDATE
            ');
            $stmtPos->execute(['uid' => $user['id'], 'code' => $stockCode]);
            $pos = $stmtPos->fetch();

            if (!$pos || (int)$pos['quantity'] < $quantity) {
                DB::rollBack();
                $holdQty = $pos ? (int)$pos['quantity'] : 0;
                errorResponse(sprintf('持仓不足。当前持仓 %d 股，拟卖出 %d 股', $holdQty, $quantity));
            }

            // 3. 锁定股票信息
            $stmtStock = $db->prepare('SELECT * FROM stocks WHERE code = :code FOR UPDATE');
            $stmtStock->execute(['code' => $stockCode]);
            $stock = $stmtStock->fetch();

            if (!$stock) {
                DB::rollBack();
                errorResponse('股票不存在: ' . htmlspecialchars($stockCode));
            }
            if ($stock['status'] === 'CIRCUIT_BREAKER') {
                DB::rollBack();
                errorResponse('该股票当前处于熔断冷静期，暂停交易');
            }
            if ($stock['status'] === 'HALTED') {
                DB::rollBack();
                errorResponse('该股票当前已停牌');
            }

            $currentPrice = (float)$stock['current_price'];
            $prevClose = (float)$stock['prev_close'];
            $depth = max(1000, (int)$stock['liquidity_depth']);
            $avgCost = (float)$pos['avg_cost'];

            // 4. 计算结算资金与手续费 (零佣金免税模式)
            $tradeAmount = round($currentPrice * $quantity, 2);
            $fee = SELL_FEE_RATE > 0 ? max(5.0, round($tradeAmount * SELL_FEE_RATE, 2)) : 0.0;
            $netIncome = round($tradeAmount - $fee, 2);
            $realizedPnl = round(($currentPrice - $avgCost) * $quantity - $fee, 2);

            // 5. 更新持仓
            $remainingQty = (int)$pos['quantity'] - $quantity;
            $remAvailQty = max(0, (int)$pos['available_quantity'] - $quantity);

            if ($remainingQty > 0) {
                $stmtUpPos = $db->prepare('
                    UPDATE positions SET 
                        quantity = :q, 
                        available_quantity = :aq, 
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE id = :id
                ');
                $stmtUpPos->execute(['q' => $remainingQty, 'aq' => $remAvailQty, 'id' => $pos['id']]);
            } else {
                $stmtDelPos = $db->prepare('DELETE FROM positions WHERE id = :id');
                $stmtDelPos->execute(['id' => $pos['id']]);
            }

            // 6. 增加用户可用资金
            $stmtUser = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
            $stmtUser->execute(['id' => $user['id']]);
            $userBal = $stmtUser->fetch();
            $newBalance = round((float)$userBal['balance'] + $netIncome, 4);

            $stmtUpBal = $db->prepare('UPDATE users SET balance = :bal, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUpBal->execute(['bal' => $newBalance, 'id' => $user['id']]);

            // 7. 写入订单与成交明细
            $stmtOrder = $db->prepare('
                INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id)
                VALUES (:uid, :code, \'SELL\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id
            ');
            $stmtOrder->execute([
                'uid' => $user['id'],
                'code' => $stockCode,
                'p' => $currentPrice,
                'q' => $quantity,
                'fq' => $quantity,
                'req' => $clientReqId ?: null
            ]);
            $orderId = $stmtOrder->fetchColumn();

            $stmtTrade = $db->prepare('
                INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl)
                VALUES (:oid, :uid, :code, \'SELL\', :p, :q, :amt, :fee, :pnl) RETURNING id
            ');
            $stmtTrade->execute([
                'oid' => $orderId,
                'uid' => $user['id'],
                'code' => $stockCode,
                'p' => $currentPrice,
                'q' => $quantity,
                'amt' => $tradeAmount,
                'fee' => $fee,
                'pnl' => $realizedPnl
            ]);
            $tradeId = $stmtTrade->fetchColumn();

            // 8. 写入资金流水
            $stmtLedger = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                VALUES (:uid, \'SELL_STOCK\', :amt, :bal_after, :lm, \'TRADE\', :ref, :desc)
            ');
            $stmtLedger->execute([
                'uid' => $user['id'],
                'amt' => $netIncome,
                'bal_after' => $newBalance,
                'lm' => (float)$userBal['locked_margin'],
                'ref' => (string)$tradeId,
                'desc' => sprintf('卖出 %s (%s) %d 股 @ ¥%s，扣税费 ¥%s，实现盈亏 ¥%s', 
                    $stock['name'], $stockCode, $quantity, $currentPrice, $fee, $realizedPnl)
            ]);

            // 9. 订单对价格的影响 (卖出施加砸盘压力)
            $pressure = min(0.09, ($quantity / $depth) * MARKET_SENSITIVITY);
            $impactPrice = round($currentPrice * (1.0 - $pressure), 2);
            $limitUp = round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2);
            $limitDown = round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2);
            $finalPrice = max($limitDown, min($limitUp, $impactPrice));

            $stmtUpStock = $db->prepare('
                UPDATE stocks SET 
                    current_price = :p,
                    high_price = GREATEST(high_price, :p),
                    low_price = LEAST(low_price, :p),
                    volume = volume + :vol,
                    amount = amount + :amt,
                    updated_at = CURRENT_TIMESTAMP
                WHERE code = :code
            ');
            $stmtUpStock->execute([
                'p' => $finalPrice,
                'vol' => $quantity,
                'amt' => $tradeAmount,
                'code' => $stockCode
            ]);

            // 10. 记录 K 线更新
            $minBucket = date('Y-m-d H:i:00');
            $stmtCandle = $db->prepare('
                INSERT INTO candles (stock_code, period, candle_time, open, high, low, close, volume, amount)
                VALUES (:code, \'1m\', :time, :p, :p, :p, :p, :vol, :amt)
                ON CONFLICT (stock_code, period, candle_time) DO UPDATE SET
                    high = GREATEST(candles.high, EXCLUDED.high),
                    low = LEAST(candles.low, EXCLUDED.low),
                    close = EXCLUDED.close,
                    volume = candles.volume + EXCLUDED.volume,
                    amount = candles.amount + EXCLUDED.amount
            ');
            $stmtCandle->execute([
                'code' => $stockCode,
                'time' => $minBucket,
                'p' => $finalPrice,
                'vol' => $quantity,
                'amt' => $tradeAmount
            ]);

            DB::commit();

            jsonResponse([
                'trade_id' => (int)$tradeId,
                'stock_code' => $stockCode,
                'stock_name' => $stock['name'],
                'direction' => 'SELL',
                'executed_price' => $currentPrice,
                'new_market_price' => $finalPrice,
                'quantity' => $quantity,
                'trade_amount' => $tradeAmount,
                'fee' => $fee,
                'realized_pnl' => $realizedPnl,
                'balance' => $newBalance,
                'remaining_quantity' => $remainingQty
            ], sprintf('卖出成功！以 ¥%s 成交 %d 股，到账 ¥%s (盈亏: ¥%s)', 
                number_format($currentPrice, 2), $quantity, number_format($netIncome, 2), number_format($realizedPnl, 2)));

        } catch (Exception $e) {
            DB::rollBack();
            error_log('Sell error: ' . $e->getMessage());
            errorResponse('卖出交易失败，请稍后重试或联系管理员', 500);
        }
        break;

    default:
        errorResponse('未知的交易操作', 404);
        break;
}
