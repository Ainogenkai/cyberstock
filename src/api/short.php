<?php
/**
 * 赛博多人股市 - 融券做空机制与强制平仓引擎
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// 仅当本文件为直接请求入口时执行路由；被 market.php 引入时只提供强平引擎函数
$isDirectRequest = isApiEntryPoint(__FILE__);

/**
 * 维持保证金巡检与自动强制平仓
 * 供 market.php 每个 Tick 或交易前后调用
 */
function executeLiquidationCheck(PDO $db): array {
    $stmtPos = $db->query('
        SELECT p.id, p.user_id, p.stock_code, p.quantity, p.avg_cost, p.locked_margin,
               s.name AS stock_name, s.current_price, s.prev_close, s.liquidity_depth
        FROM positions p
        JOIN stocks s ON p.stock_code = s.code
        WHERE p.position_type = \'SHORT\' AND p.quantity > 0
        FOR UPDATE OF p
    ');
    $shortPositions = $stmtPos->fetchAll();
    $liquidated = [];

    foreach ($shortPositions as $pos) {
        $posId = (int)$pos['id'];
        $userId = (int)$pos['user_id'];
        $code = $pos['stock_code'];
        $qty = (int)$pos['quantity'];
        $avgCost = (float)$pos['avg_cost'];
        $lockedMargin = (float)$pos['locked_margin'];
        $curPrice = (float)$pos['current_price'];
        $depth = max(1000, (int)$pos['liquidity_depth']);

        // 浮动盈亏 = (开仓均价 - 现价) * 数量
        $floatingPnl = round(($avgCost - $curPrice) * $qty, 2);
        // 当前持仓净值 = 锁定保证金 + 浮动盈亏
        $equity = $lockedMargin + $floatingPnl;
        $notionalValue = round($curPrice * $qty, 2);

        // 维持保证金比例 = 净值 / 名义市值
        $marginRatio = ($notionalValue > 0) ? ($equity / $notionalValue) : 1.0;

        // 如果保证金比例低于维持保证金阈值 (默认 30%)，触发强制平仓！
        if ($marginRatio < MAINTENANCE_MARGIN_RATE) {
            // 锁定用户资金行
            $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
            $stmtU->execute(['id' => $userId]);
            $userRow = $stmtU->fetch();

            $penaltyFee = round($notionalValue * 0.005, 2); // 0.5% 强平惩罚违约金
            $returnFund = max(0.0, round($equity - $penaltyFee, 2));

            $newBal = round((float)$userRow['balance'] + $returnFund, 4);
            $newLock = max(0.0, round((float)$userRow['locked_margin'] - $lockedMargin, 4));

            // 更新用户资金
            $stmtUpU = $db->prepare('UPDATE users SET balance = :b, locked_margin = :lm, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUpU->execute(['b' => $newBal, 'lm' => $newLock, 'id' => $userId]);

            // 删除该空头持仓
            $stmtDel = $db->prepare('DELETE FROM positions WHERE id = :id');
            $stmtDel->execute(['id' => $posId]);

            // 写入成交记录 (方向为 LIQUIDATION)
            $stmtTr = $db->prepare('
                INSERT INTO trades (user_id, stock_code, direction, price, quantity, amount, fee, pnl)
                VALUES (:uid, :code, \'LIQUIDATION\', :p, :q, :amt, :fee, :pnl) RETURNING id
            ');
            $stmtTr->execute([
                'uid' => $userId,
                'code' => $code,
                'p' => $curPrice,
                'q' => $qty,
                'amt' => $notionalValue,
                'fee' => $penaltyFee,
                'pnl' => $floatingPnl
            ]);
            $trId = $stmtTr->fetchColumn();

            // 写入账目流水
            $stmtLedger = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                VALUES (:uid, \'LIQUIDATION\', :amt, :bal, :lm, \'LIQUIDATION\', :ref, :desc)
            ');
            $stmtLedger->execute([
                'uid' => $userId,
                'amt' => $returnFund,
                'bal' => $newBal,
                'lm' => $newLock,
                'ref' => (string)$trId,
                'desc' => sprintf('【系统强制平仓】空头持仓 %s (%s) 维持担保比例跌破 30%% (当前 %.2f%%)，按现价 ¥%s 强平 %d 股，退回剩余保证金 ¥%s', 
                    $pos['stock_name'], $code, $marginRatio * 100, $curPrice, $qty, $returnFund)
            ]);

            // 强平买券平仓产生的推高买压
            $pressure = min(0.09, ($qty / $depth) * MARKET_SENSITIVITY);
            $newPrice = round($curPrice * (1.0 + $pressure), 2);
            $limitUp = round((float)$pos['prev_close'] * (1.0 + PRICE_LIMIT_RATE), 2);
            $finalPrice = min($limitUp, $newPrice);

            $stmtUpSt = $db->prepare('UPDATE stocks SET current_price = :p, volume = volume + :v, updated_at = CURRENT_TIMESTAMP WHERE code = :c');
            $stmtUpSt->execute(['p' => $finalPrice, 'v' => $qty, 'c' => $code]);

            // 自动发布逼空/强平市场事件
            $stmtEv = $db->prepare('
                INSERT INTO market_events (title, content, category, source_type, stock_code, impact_factor, duration_sec, expires_at)
                VALUES (:t, :c, \'SHORT_SQUEEZE\', \'PLAYER_ACTION\', :code, 0.03, 180, CURRENT_TIMESTAMP + INTERVAL \'180 seconds\')
            ');
            $stmtEv->execute([
                't' => sprintf('市场异动：【%s】触发空头逼空强制平仓！', $pos['stock_name']),
                'c' => sprintf('股票 %s (%s) 因价格快速冲高，空头维持保证金比例不足引发系统连环强平买入，推动股价进一步反弹！', $pos['stock_name'], $code),
                'code' => $code
            ]);

            $liquidated[] = [
                'user_id' => $userId,
                'stock_code' => $code,
                'quantity' => $qty,
                'price' => $curPrice,
                'loss' => -$floatingPnl
            ];
        }
    }

    return $liquidated;
}

// 仅在直接请求本文件时鉴权并处理路由
if ($isDirectRequest) {
    $user = requireAuth();
    $db = DB::getConnection();
    $action = $_GET['action'] ?? '';
    $input = getJsonInput();

    switch ($action) {
        case 'open':
            // 开仓做空 (融券卖出)
            $stockCode = trim($input['stock_code'] ?? '');
            $quantity = (int)($input['quantity'] ?? 0);
            $clientReqId = trim($input['client_req_id'] ?? '');

            if (empty($stockCode) || $quantity <= 0) {
                errorResponse('请输入合法的股票代码和做空股数 (正整数)');
            }

            DB::beginTransaction();
            try {
                if (!empty($clientReqId)) {
                    $stmtReq = $db->prepare('SELECT id FROM orders WHERE client_req_id = :req AND user_id = :uid');
                    $stmtReq->execute(['req' => $clientReqId, 'uid' => $user['id']]);
                    if ($stmtReq->fetch()) {
                        DB::rollBack();
                        errorResponse('请勿重复提交同一笔做空订单');
                    }
                }

                $stmtStock = $db->prepare('SELECT * FROM stocks WHERE code = :code FOR UPDATE');
                $stmtStock->execute(['code' => $stockCode]);
                $stock = $stmtStock->fetch();

                if (!$stock) {
                    DB::rollBack();
                    errorResponse('股票不存在: ' . htmlspecialchars($stockCode));
                }
                if ($stock['status'] !== 'TRADING') {
                    DB::rollBack();
                    errorResponse('该股票目前不可交易 (状态: ' . $stock['status'] . ')');
                }

                $curPrice = (float)$stock['current_price'];
                $prevClose = (float)$stock['prev_close'];
                $depth = max(1000, (int)$stock['liquidity_depth']);

                // 2.3 阶梯解锁校验：检查全服玩家人数是否达到解禁门槛
                $playerCount = max(1, (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn());
                $requiredUsers = (int)($stock['unlock_required_users'] ?? 1);
                if ($requiredUsers > $playerCount) {
                    DB::rollBack();
                    errorResponse(sprintf('【尚未解禁】当前全服玩家仅 %d 人，该股票需全服满 %d 人方可解禁做空！快叫群友注册进场解锁！', $playerCount, $requiredUsers), 400);
                }

                // 2.5 融券借券池额度校验：券池容量随全服总资金动态扩容
                $totalCapital = (float)$db->query("SELECT COALESCE(SUM(balance + locked_margin), 0) FROM users")->fetchColumn();
                $capitalMultiplier = max(1.0, 1.0 + ($totalCapital - 2000000.0) / 10000000.0);
                $maxShortShares = (int)(($stock['max_short_shares'] ?? 60000) * $capitalMultiplier);
                $stmtShortHeld = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :code AND position_type = 'SHORT'");
                $stmtShortHeld->execute(['code' => $stockCode]);
                $totalBorrowedShort = (int)$stmtShortHeld->fetchColumn();

                $availableShortShares = max(0, $maxShortShares - $totalBorrowedShort);
                if ($quantity > $availableShortShares) {
                    DB::rollBack();
                    errorResponse(sprintf('【融券池已枯竭】%s(%s) 全服融券池借券上限为 %s 股，已被做空借出 %s 股，剩余可融券额度仅剩 %s 股！无法继续做空 %d 股。',
                        $stock['name'], $stockCode, number_format($maxShortShares), number_format($totalBorrowedShort), number_format($availableShortShares), $quantity), 400, [
                        'available_short_shares' => $availableShortShares,
                        'max_short_shares' => $maxShortShares,
                        'borrowed_short_shares' => $totalBorrowedShort
                    ]);
                }

                // 初始保证金需求 = 现价 * 数量 * 保证金率 (100%)
                $notional = round($curPrice * $quantity, 2);
                $requiredMargin = round($notional * SHORT_MARGIN_RATE, 2);
                $fee = SELL_FEE_RATE > 0 ? max(5.0, round($notional * SELL_FEE_RATE, 2)) : 0.0;
                $totalLock = round($requiredMargin + $fee, 2);

                $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
                $stmtU->execute(['id' => $user['id']]);
                $uBal = $stmtU->fetch();
                $availBalance = (float)$uBal['balance'];

                if ($availBalance < $totalLock) {
                    DB::rollBack();
                    errorResponse(sprintf('做空可用保证金不足。需要: ¥%s (含手续费 ¥%s)，当前可用: ¥%s',
                        number_format($totalLock, 2), number_format($fee, 2), number_format($availBalance, 2)));
                }

                // 扣减可用资金，转入锁定保证金
                $newBal = round($availBalance - $totalLock, 4);
                $newLock = round((float)$uBal['locked_margin'] + $requiredMargin, 4);

                $stmtUpU = $db->prepare('UPDATE users SET balance = :b, locked_margin = :lm, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmtUpU->execute(['b' => $newBal, 'lm' => $newLock, 'id' => $user['id']]);

                // 更新/建立空头持仓
                $stmtPos = $db->prepare('
                    SELECT id, quantity, avg_cost, locked_margin 
                    FROM positions 
                    WHERE user_id = :uid AND stock_code = :c AND position_type = \'SHORT\' 
                    FOR UPDATE
                ');
                $stmtPos->execute(['uid' => $user['id'], 'c' => $stockCode]);
                $shortPos = $stmtPos->fetch();

                if ($shortPos) {
                    $oldQty = (int)$shortPos['quantity'];
                    $oldCost = (float)$shortPos['avg_cost'];
                    $oldMargin = (float)$shortPos['locked_margin'];
                    $newQty = $oldQty + $quantity;
                    $newCost = round(($oldQty * $oldCost + $notional) / $newQty, 4);
                    $newPosMargin = round($oldMargin + $requiredMargin, 2);

                    $stmtUpP = $db->prepare('
                        UPDATE positions SET 
                            quantity = :q, 
                            available_quantity = :q, 
                            avg_cost = :c, 
                            locked_margin = :lm, 
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = :id
                    ');
                    $stmtUpP->execute(['q' => $newQty, 'c' => $newCost, 'lm' => $newPosMargin, 'id' => $shortPos['id']]);
                } else {
                    $newQty = $quantity;
                    $newCost = $curPrice;
                    $newPosMargin = $requiredMargin;

                    $stmtInsP = $db->prepare('
                        INSERT INTO positions (user_id, stock_code, position_type, quantity, available_quantity, avg_cost, locked_margin)
                        VALUES (:uid, :c, \'SHORT\', :q, :q, :cost, :lm)
                    ');
                    $stmtInsP->execute([
                        'uid' => $user['id'],
                        'c' => $stockCode,
                        'q' => $newQty,
                        'cost' => $newCost,
                        'lm' => $newPosMargin
                    ]);
                }

                // 写入订单与成交
                $stmtOrd = $db->prepare('
                    INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id)
                    VALUES (:uid, :code, \'SHORT_OPEN\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id
                ');
                $stmtOrd->execute([
                    'uid' => $user['id'],
                    'code' => $stockCode,
                    'p' => $curPrice,
                    'q' => $quantity,
                    'fq' => $quantity,
                    'req' => $clientReqId ?: null
                ]);
                $ordId = $stmtOrd->fetchColumn();

                $stmtTr = $db->prepare('
                    INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl)
                    VALUES (:oid, :uid, :code, \'SHORT_OPEN\', :p, :q, :amt, :fee, 0) RETURNING id
                ');
                $stmtTr->execute([
                    'oid' => $ordId,
                    'uid' => $user['id'],
                    'code' => $stockCode,
                    'p' => $curPrice,
                    'q' => $quantity,
                    'amt' => $notional,
                    'fee' => $fee
                ]);
                $trId = $stmtTr->fetchColumn();

                // 记账流水
                $stmtLedger = $db->prepare('
                    INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                    VALUES (:uid, \'SHORT_MARGIN_LOCK\', :amt, :bal, :lm, \'TRADE\', :ref, :desc)
                ');
                $stmtLedger->execute([
                    'uid' => $user['id'],
                    'amt' => -$totalLock,
                    'bal' => $newBal,
                    'lm' => $newLock,
                    'ref' => (string)$trId,
                    'desc' => sprintf('开仓做空 %s (%s) %d 股 @ ¥%s，锁定保证金 ¥%s，手续费 ¥%s',
                        $stock['name'], $stockCode, $quantity, $curPrice, $requiredMargin, $fee)
                ]);

                // 做空施加卖出打压价格冲击
                $pressure = min(0.09, ($quantity / $depth) * MARKET_SENSITIVITY);
                $impactPrice = round($curPrice * (1.0 - $pressure), 2);
                $limitDown = round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2);
                $finalPrice = max($limitDown, $impactPrice);

                $stmtUpSt = $db->prepare('UPDATE stocks SET current_price = :p, volume = volume + :v, amount = amount + :amt, updated_at = CURRENT_TIMESTAMP WHERE code = :c');
                $stmtUpSt->execute(['p' => $finalPrice, 'v' => $quantity, 'amt' => $notional, 'c' => $stockCode]);

                DB::commit();

                jsonResponse([
                    'trade_id' => (int)$trId,
                    'stock_code' => $stockCode,
                    'stock_name' => $stock['name'],
                    'direction' => 'SHORT_OPEN',
                    'price' => $curPrice,
                    'quantity' => $quantity,
                    'locked_margin' => $requiredMargin,
                    'balance' => $newBal,
                    'total_locked_margin' => $newLock,
                    'short_quantity' => $newQty,
                    'avg_cost' => $newCost
                ], sprintf('做空开仓成功！以 ¥%s 开空 %d 股，锁定保证金 ¥%s', number_format($curPrice, 2), $quantity, number_format($requiredMargin, 2)));

            } catch (Exception $e) {
                DB::rollBack();
                error_log('Short open error: ' . $e->getMessage());
                errorResponse('做空开仓失败，请稍后重试或联系管理员', 500);
            }
            break;

        case 'close':
            // 平仓买还 (买券平仓)
            $stockCode = trim($input['stock_code'] ?? '');
            $quantity = (int)($input['quantity'] ?? 0);
            $clientReqId = trim($input['client_req_id'] ?? '');

            if (empty($stockCode) || $quantity <= 0) {
                errorResponse('请输入合法的股票代码和平仓数量 (正整数)');
            }

            DB::beginTransaction();
            try {
                if (!empty($clientReqId)) {
                    $stmtReq = $db->prepare('SELECT id FROM orders WHERE client_req_id = :req AND user_id = :uid');
                    $stmtReq->execute(['req' => $clientReqId, 'uid' => $user['id']]);
                    if ($stmtReq->fetch()) {
                        DB::rollBack();
                        errorResponse('请勿重复提交同一笔平仓订单');
                    }
                }

                $stmtPos = $db->prepare('
                    SELECT id, quantity, avg_cost, locked_margin 
                    FROM positions 
                    WHERE user_id = :uid AND stock_code = :c AND position_type = \'SHORT\' 
                    FOR UPDATE
                ');
                $stmtPos->execute(['uid' => $user['id'], 'c' => $stockCode]);
                $shortPos = $stmtPos->fetch();

                if (!$shortPos || (int)$shortPos['quantity'] < $quantity) {
                    DB::rollBack();
                    $curQty = $shortPos ? (int)$shortPos['quantity'] : 0;
                    errorResponse(sprintf('空头持仓不足。当前持有空单 %d 股，拟平仓 %d 股', $curQty, $quantity));
                }

                $stmtStock = $db->prepare('SELECT * FROM stocks WHERE code = :code FOR UPDATE');
                $stmtStock->execute(['code' => $stockCode]);
                $stock = $stmtStock->fetch();

                $curPrice = (float)$stock['current_price'];
                $prevClose = (float)$stock['prev_close'];
                $depth = max(1000, (int)$stock['liquidity_depth']);

                $posQty = (int)$shortPos['quantity'];
                $avgCost = (float)$shortPos['avg_cost'];
                $posMargin = (float)$shortPos['locked_margin'];

                // 平仓比例
                $ratio = $quantity / (float)$posQty;
                $releasedMargin = round($posMargin * $ratio, 2);

                // 实现盈亏 = (开仓均价 - 平仓现价) * 平仓数量 (零佣金免税)
                $grossPnl = round(($avgCost - $curPrice) * $quantity, 2);
                $fee = BUY_FEE_RATE > 0 ? max(5.0, round($curPrice * $quantity * BUY_FEE_RATE, 2)) : 0.0;
                $netPnl = round($grossPnl - $fee, 2);

                // 返还资金 = 释放保证金 + 净盈亏
                $returnCash = round($releasedMargin + $netPnl, 2);

                // 更新用户资金账户
                $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
                $stmtU->execute(['id' => $user['id']]);
                $uBal = $stmtU->fetch();

                $newBal = round((float)$uBal['balance'] + max(0.0, $returnCash), 4);
                $newLock = max(0.0, round((float)$uBal['locked_margin'] - $releasedMargin, 4));

                $stmtUpU = $db->prepare('UPDATE users SET balance = :b, locked_margin = :lm, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmtUpU->execute(['b' => $newBal, 'lm' => $newLock, 'id' => $user['id']]);

                // 更新空头持仓
                $remQty = $posQty - $quantity;
                $remMargin = round($posMargin - $releasedMargin, 2);

                if ($remQty > 0) {
                    $stmtUpP = $db->prepare('
                        UPDATE positions SET 
                            quantity = :q, 
                            available_quantity = :q, 
                            locked_margin = :lm, 
                            updated_at = CURRENT_TIMESTAMP 
                        WHERE id = :id
                    ');
                    $stmtUpP->execute(['q' => $remQty, 'lm' => $remMargin, 'id' => $shortPos['id']]);
                } else {
                    $stmtDelP = $db->prepare('DELETE FROM positions WHERE id = :id');
                    $stmtDelP->execute(['id' => $shortPos['id']]);
                }

                // 记录订单与成交
                $stmtOrd = $db->prepare('
                    INSERT INTO orders (user_id, stock_code, direction, order_type, price, quantity, filled_quantity, status, client_req_id)
                    VALUES (:uid, :code, \'SHORT_CLOSE\', \'MARKET\', :p, :q, :fq, \'FILLED\', :req) RETURNING id
                ');
                $stmtOrd->execute([
                    'uid' => $user['id'],
                    'code' => $stockCode,
                    'p' => $curPrice,
                    'q' => $quantity,
                    'fq' => $quantity,
                    'req' => $clientReqId ?: null
                ]);
                $ordId = $stmtOrd->fetchColumn();

                $stmtTr = $db->prepare('
                    INSERT INTO trades (order_id, user_id, stock_code, direction, price, quantity, amount, fee, pnl)
                    VALUES (:oid, :uid, :code, \'SHORT_CLOSE\', :p, :q, :amt, :fee, :pnl) RETURNING id
                ');
                $stmtTr->execute([
                    'oid' => $ordId,
                    'uid' => $user['id'],
                    'code' => $stockCode,
                    'p' => $curPrice,
                    'q' => $quantity,
                    'amt' => round($curPrice * $quantity, 2),
                    'fee' => $fee,
                    'pnl' => $netPnl
                ]);
                $trId = $stmtTr->fetchColumn();

                // 记账流水
                $stmtLedger = $db->prepare('
                    INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                    VALUES (:uid, \'SHORT_MARGIN_RELEASE\', :amt, :bal, :lm, \'TRADE\', :ref, :desc)
                ');
                $stmtLedger->execute([
                    'uid' => $user['id'],
                    'amt' => $returnCash,
                    'bal' => $newBal,
                    'lm' => $newLock,
                    'ref' => (string)$trId,
                    'desc' => sprintf('平仓空头 %s (%s) %d 股 @ ¥%s，释放保证金 ¥%s，实现盈亏 ¥%s，手续费 ¥%s',
                        $stock['name'], $stockCode, $quantity, $curPrice, $releasedMargin, $grossPnl, $fee)
                ]);

                // 平仓买还推高价格
                $pressure = min(0.09, ($quantity / $depth) * MARKET_SENSITIVITY);
                $impactPrice = round($curPrice * (1.0 + $pressure), 2);
                $limitUp = round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2);
                $finalPrice = min($limitUp, $impactPrice);

                $stmtUpSt = $db->prepare('UPDATE stocks SET current_price = :p, volume = volume + :v, updated_at = CURRENT_TIMESTAMP WHERE code = :c');
                $stmtUpSt->execute(['p' => $finalPrice, 'v' => $quantity, 'c' => $stockCode]);

                DB::commit();

                jsonResponse([
                    'trade_id' => (int)$trId,
                    'stock_code' => $stockCode,
                    'stock_name' => $stock['name'],
                    'direction' => 'SHORT_CLOSE',
                    'price' => $curPrice,
                    'quantity' => $quantity,
                    'realized_pnl' => $netPnl,
                    'returned_cash' => $returnCash,
                    'balance' => $newBal,
                    'remaining_short_quantity' => $remQty
                ], sprintf('平仓成功！以 ¥%s 买还 %d 股，实现盈亏 ¥%s，返还可用资金 ¥%s', 
                    number_format($curPrice, 2), $quantity, number_format($netPnl, 2), number_format($returnCash, 2)));

            } catch (Exception $e) {
                DB::rollBack();
                error_log('Short close error: ' . $e->getMessage());
                errorResponse('平仓失败，请稍后重试或联系管理员', 500);
            }
            break;

        case 'positions':
            // 查询当前用户的空头持仓
            $stmt = $db->prepare('
                SELECT p.id, p.stock_code, s.name AS stock_name, s.industry, s.current_price, 
                       p.quantity, p.avg_cost, p.locked_margin,
                       ROUND(((p.avg_cost - s.current_price) * p.quantity), 2) AS floating_pnl,
                       ROUND(((p.avg_cost - s.current_price) / p.avg_cost * 100.0), 2) AS floating_pnl_percent,
                       ROUND(((p.locked_margin + (p.avg_cost - s.current_price) * p.quantity) / (s.current_price * p.quantity) * 100.0), 2) AS margin_ratio_percent,
                       ROUND((p.avg_cost + (p.locked_margin / p.quantity) * (1.0 - ' . MAINTENANCE_MARGIN_RATE . ')), 2) AS est_liquidation_price
                FROM positions p
                JOIN stocks s ON p.stock_code = s.code
                WHERE p.user_id = :uid AND p.position_type = \'SHORT\' AND p.quantity > 0
                ORDER BY p.updated_at DESC
            ');
            $stmt->execute(['uid' => $user['id']]);
            $rows = $stmt->fetchAll();

            $formatted = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'stock_code' => $r['stock_code'],
                    'stock_name' => $r['stock_name'],
                    'industry' => $r['industry'],
                    'current_price' => (float)$r['current_price'],
                    'quantity' => (int)$r['quantity'],
                    'avg_cost' => (float)$r['avg_cost'],
                    'locked_margin' => (float)$r['locked_margin'],
                    'floating_pnl' => (float)$r['floating_pnl'],
                    'floating_pnl_percent' => (float)$r['floating_pnl_percent'],
                    'margin_ratio_percent' => (float)$r['margin_ratio_percent'],
                    'est_liquidation_price' => (float)$r['est_liquidation_price'],
                    'is_danger' => ((float)$r['margin_ratio_percent'] <= 50.0)
                ];
            }, $rows);

            jsonResponse(['short_positions' => $formatted]);
            break;

        default:
            errorResponse('未知的做空操作', 404);
            break;
    }
}
