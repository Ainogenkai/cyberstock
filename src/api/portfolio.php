<?php
/**
 * 赛博多人股市 - 个人投资组合、资产透视与交易账目流水
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = requireAuth();
$db = DB::getConnection();
$action = $_GET['action'] ?? 'overview';

switch ($action) {
    case 'overview':
        // 1. 获取用户账户最新资金
        $stmtU = $db->prepare('SELECT id, balance, locked_margin, initial_fund FROM users WHERE id = :id');
        $stmtU->execute(['id' => $user['id']]);
        $uRow = $stmtU->fetch();

        $balance = (float)$uRow['balance'];
        $lockedMargin = (float)$uRow['locked_margin'];
        $initialFund = (float)$uRow['initial_fund'];

        // 2. 获取多头持仓及现价市值
        $stmtLong = $db->prepare('
            SELECT p.id, p.stock_code, s.name AS stock_name, s.industry, s.current_price,
                   p.quantity, p.available_quantity, p.avg_cost,
                   ROUND((p.quantity * s.current_price), 2) AS market_value,
                   ROUND(((s.current_price - p.avg_cost) * p.quantity), 2) AS floating_pnl,
                   ROUND(((s.current_price - p.avg_cost) / GREATEST(0.0001, p.avg_cost) * 100.0), 2) AS floating_pnl_percent
            FROM positions p
            JOIN stocks s ON p.stock_code = s.code
            WHERE p.user_id = :uid AND p.position_type = \'LONG\' AND p.quantity > 0
            ORDER BY market_value DESC
        ');
        $stmtLong->execute(['uid' => $user['id']]);
        $longPositions = $stmtLong->fetchAll();

        $longMarketValue = 0.0;
        $longFloatingPnl = 0.0;
        foreach ($longPositions as &$lp) {
            $lp['current_price'] = (float)$lp['current_price'];
            $lp['avg_cost'] = (float)$lp['avg_cost'];
            $lp['quantity'] = (int)$lp['quantity'];
            $lp['available_quantity'] = (int)$lp['available_quantity'];
            $lp['market_value'] = (float)$lp['market_value'];
            $lp['floating_pnl'] = (float)$lp['floating_pnl'];
            $lp['floating_pnl_percent'] = (float)$lp['floating_pnl_percent'];
            $longMarketValue += $lp['market_value'];
            $longFloatingPnl += $lp['floating_pnl'];
        }

        // 3. 获取空头持仓及保证金净值
        $stmtShort = $db->prepare('
            SELECT p.id, p.stock_code, s.name AS stock_name, s.industry, s.current_price,
                   p.quantity, p.avg_cost, p.locked_margin,
                   ROUND(((p.avg_cost - s.current_price) * p.quantity), 2) AS floating_pnl,
                   ROUND(((p.avg_cost - s.current_price) / GREATEST(0.0001, p.avg_cost) * 100.0), 2) AS floating_pnl_percent,
                   ROUND(((p.locked_margin + (p.avg_cost - s.current_price) * p.quantity) / GREATEST(0.01, (s.current_price * p.quantity)) * 100.0), 2) AS margin_ratio_percent,
                   ROUND((p.avg_cost + (p.locked_margin / GREATEST(1, p.quantity)) * (1.0 - ' . MAINTENANCE_MARGIN_RATE . ')), 2) AS est_liquidation_price
            FROM positions p
            JOIN stocks s ON p.stock_code = s.code
            WHERE p.user_id = :uid AND p.position_type = \'SHORT\' AND p.quantity > 0
            ORDER BY p.updated_at DESC
        ');
        $stmtShort->execute(['uid' => $user['id']]);
        $shortPositions = $stmtShort->fetchAll();

        $shortFloatingPnl = 0.0;
        foreach ($shortPositions as &$sp) {
            $sp['current_price'] = (float)$sp['current_price'];
            $sp['avg_cost'] = (float)$sp['avg_cost'];
            $sp['quantity'] = (int)$sp['quantity'];
            $sp['locked_margin'] = (float)$sp['locked_margin'];
            $sp['floating_pnl'] = (float)$sp['floating_pnl'];
            $sp['floating_pnl_percent'] = (float)$sp['floating_pnl_percent'];
            $sp['margin_ratio_percent'] = (float)$sp['margin_ratio_percent'];
            $sp['est_liquidation_price'] = (float)$sp['est_liquidation_price'];
            $shortFloatingPnl += $sp['floating_pnl'];
        }

        // 4. 计算总资产与总收益率
        // 总资产 = 可用现金 + 锁定保证金 + 多头市值 + 空头浮盈
        $totalAssets = round($balance + $lockedMargin + $longMarketValue + $shortFloatingPnl, 2);
        $totalFloatingPnl = round($longFloatingPnl + $shortFloatingPnl, 2);
        $totalReturn = round($totalAssets - $initialFund, 2);
        $totalReturnPercent = round(($totalReturn / max(1.0, $initialFund)) * 100.0, 2);

        // 5. 统计用户累计成就与交易数据 (供前端个人主页与成就系统评估)
        $stmtTrAll = $db->prepare('
            SELECT t.direction, t.pnl, t.amount, t.fee, t.stock_code, s.industry,
                   TO_CHAR(t.created_at, \'YYYY-MM-DD\') AS trade_date
            FROM trades t
            JOIN stocks s ON t.stock_code = s.code
            WHERE t.user_id = :uid
            ORDER BY t.created_at ASC
        ');
        $stmtTrAll->execute(['uid' => $user['id']]);
        $allTrades = $stmtTrAll->fetchAll();

        $tradeCount = count($allTrades);
        $totalProfit = 0.0;
        $totalLoss = 0.0;
        $totalFees = 0.0;
        $largeTrades = 0;
        $holdMaotai = false;
        $sectorsTraded = [];
        $dayTradeMap = [];
        $currStreak = 0;
        $maxStreak = 0;
        $doubleBaggers = 0;

        foreach ($allTrades as $tr) {
            $pnl = (float)$tr['pnl'];
            $amt = (float)$tr['amount'];
            $fee = (float)$tr['fee'];
            $totalFees += $fee;

            if ($amt >= 100000.0) {
                $largeTrades++;
            }
            if ($tr['stock_code'] === '600519') {
                $holdMaotai = true;
            }
            if (!empty($tr['industry'])) {
                $sectorsTraded[$tr['industry']] = true;
            }

            $dtKey = $tr['trade_date'] . '_' . $tr['stock_code'];
            $dayTradeMap[$dtKey] = ($dayTradeMap[$dtKey] ?? 0) + 1;

            if ($pnl > 0) {
                $totalProfit += $pnl;
                $currStreak++;
                if ($currStreak > $maxStreak) $maxStreak = $currStreak;
            } elseif ($pnl < 0) {
                $totalLoss += abs($pnl);
                $currStreak = 0;
            }
        }

        foreach ($longPositions as $lp) {
            if ($lp['stock_code'] === '600519') {
                $holdMaotai = true;
            }
            if (!empty($lp['floating_pnl_percent']) && (float)$lp['floating_pnl_percent'] >= 100.0) {
                $doubleBaggers++;
            }
        }

        $dayTradesCount = 0;
        foreach ($dayTradeMap as $cnt) {
            if ($cnt >= 2) $dayTradesCount++;
        }

        $stats = [
            'tradeCount' => $tradeCount,
            'totalProfit' => round($totalProfit, 2),
            'totalLoss' => round($totalLoss, 2),
            'netProfit' => round($totalProfit - $totalLoss, 2),
            'maxHoldings' => count($longPositions),
            'maxReturn' => $totalReturnPercent / 100.0,
            'profitStreak' => $maxStreak,
            'sectorsTraded' => count($sectorsTraded),
            'largeTrades' => $largeTrades,
            'holdMaotai' => $holdMaotai,
            'dayTrades' => $dayTradesCount,
            'doubleBaggers' => $doubleBaggers,
            'totalFees' => round($totalFees, 2)
        ];

        jsonResponse([
            'summary' => [
                'total_assets' => $totalAssets,
                'available_cash' => $balance,
                'locked_margin' => $lockedMargin,
                'long_market_value' => round($longMarketValue, 2),
                'total_floating_pnl' => $totalFloatingPnl,
                'total_return' => $totalReturn,
                'total_return_percent' => $totalReturnPercent,
                'initial_fund' => $initialFund,
                'daily_reward' => DAILY_CHECKIN_REWARD,
                'has_checked_in_today' => (bool)($db->query("SELECT 1 FROM daily_checkins WHERE user_id = " . (int)$user['id'] . " AND checkin_date = CURRENT_DATE")->fetchColumn()),
                'can_claim_bailout' => ($totalAssets < BANKRUPTCY_THRESHOLD),
                'bankruptcy_threshold' => BANKRUPTCY_THRESHOLD,
                'bailout_amount' => BANKRUPTCY_BAILOUT_AMOUNT
            ],
            'long_positions' => $longPositions,
            'short_positions' => $shortPositions,
            'stats' => $stats
        ]);
        break;

    case 'trades':
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 50)));
        $stmt = $db->prepare('
            SELECT t.id, t.stock_code, s.name AS stock_name, t.direction, t.price, t.quantity, t.amount, t.fee, t.pnl,
                   TO_CHAR(t.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS trade_time
            FROM trades t
            JOIN stocks s ON t.stock_code = s.code
            WHERE t.user_id = :uid
            ORDER BY t.created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':uid', $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $trades = $stmt->fetchAll();

        jsonResponse([
            'trades' => array_map(function($t) {
                return [
                    'id' => (int)$t['id'],
                    'stock_code' => $t['stock_code'],
                    'stock_name' => $t['stock_name'],
                    'direction' => $t['direction'],
                    'price' => (float)$t['price'],
                    'quantity' => (int)$t['quantity'],
                    'amount' => (float)$t['amount'],
                    'fee' => (float)$t['fee'],
                    'pnl' => (float)$t['pnl'],
                    'time' => $t['trade_time']
                ];
            }, $trades)
        ]);
        break;

    case 'ledger':
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 50)));
        $stmt = $db->prepare('
            SELECT id, change_type, amount, balance_after, locked_margin_after, description,
                   TO_CHAR(created_at, \'YYYY-MM-DD HH24:MI:SS\') AS log_time
            FROM account_ledger
            WHERE user_id = :uid
            ORDER BY created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':uid', $user['id'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ledger = $stmt->fetchAll();

        jsonResponse([
            'ledger' => array_map(function($l) {
                return [
                    'id' => (int)$l['id'],
                    'change_type' => $l['change_type'],
                    'amount' => (float)$l['amount'],
                    'balance_after' => (float)$l['balance_after'],
                    'locked_margin_after' => (float)$l['locked_margin_after'],
                    'description' => $l['description'],
                    'time' => $l['log_time']
                ];
            }, $ledger)
        ]);
        break;

    case 'checkin':
        // 每日签到扶持金
        DB::beginTransaction();
        try {
            $stmtCheck = $db->prepare('SELECT id FROM daily_checkins WHERE user_id = :uid AND checkin_date = CURRENT_DATE FOR UPDATE');
            $stmtCheck->execute(['uid' => $user['id']]);
            if ($stmtCheck->fetch()) {
                DB::rollBack();
                errorResponse('您今天已经完成签到，请明天再来领取扶持金！', 400, ['has_checked_in' => true]);
            }

            // 写入签到记录
            $stmtIns = $db->prepare('INSERT INTO daily_checkins (user_id, checkin_date, reward_amount) VALUES (:uid, CURRENT_DATE, :amt)');
            $stmtIns->execute(['uid' => $user['id'], 'amt' => DAILY_CHECKIN_REWARD]);

            // 发放扶持金入账
            $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
            $stmtU->execute(['id' => $user['id']]);
            $uRow = $stmtU->fetch();
            $newBal = round((float)$uRow['balance'] + DAILY_CHECKIN_REWARD, 4);

            $stmtUp = $db->prepare('UPDATE users SET balance = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUp->execute(['b' => $newBal, 'id' => $user['id']]);

            // 写入账目流水
            $stmtL = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                VALUES (:uid, \'DAILY_CHECKIN\', :amt, :bal, :lm, \'SYSTEM\', \'CHECKIN\', :desc)
            ');
            $stmtL->execute([
                'uid' => $user['id'],
                'amt' => DAILY_CHECKIN_REWARD,
                'bal' => $newBal,
                'lm' => (float)$uRow['locked_margin'],
                'desc' => sprintf('每日签到打卡奖励金 ¥%s 到账', number_format(DAILY_CHECKIN_REWARD, 2))
            ]);

            DB::commit();
            jsonResponse([
                'reward_amount' => DAILY_CHECKIN_REWARD,
                'balance' => $newBal,
                'has_checked_in_today' => true
            ], sprintf('签到成功！已发放每日扶持金 ¥%s 元到您的可用现金账户。', number_format(DAILY_CHECKIN_REWARD, 2)));
        } catch (Exception $e) {
            DB::rollBack();
            errorResponse('签到失败，请稍后重试或联系管理员', 500);
        }
        break;

    case 'bailout':
        // 破产重组低保救济金
        DB::beginTransaction();
        try {
            // 计算用户当前真实总资产
            $stmtU = $db->prepare('SELECT balance, locked_margin FROM users WHERE id = :id FOR UPDATE');
            $stmtU->execute(['id' => $user['id']]);
            $uRow = $stmtU->fetch();
            $curBal = (float)$uRow['balance'];
            $curLock = (float)$uRow['locked_margin'];

            // 计算多头市值
            $stmtLong = $db->prepare('
                SELECT COALESCE(SUM(p.quantity * s.current_price), 0)
                FROM positions p
                JOIN stocks s ON p.stock_code = s.code
                WHERE p.user_id = :uid AND p.position_type = \'LONG\' AND p.quantity > 0
            ');
            $stmtLong->execute(['uid' => $user['id']]);
            $longVal = (float)$stmtLong->fetchColumn();

            // 计算空头浮盈
            $stmtShort = $db->prepare('
                SELECT COALESCE(SUM((p.avg_cost - s.current_price) * p.quantity), 0)
                FROM positions p
                JOIN stocks s ON p.stock_code = s.code
                WHERE p.user_id = :uid AND p.position_type = \'SHORT\' AND p.quantity > 0
            ');
            $stmtShort->execute(['uid' => $user['id']]);
            $shortPnl = (float)$stmtShort->fetchColumn();

            $realTotalAssets = round($curBal + $curLock + $longVal + $shortPnl, 2);

            if ($realTotalAssets >= BANKRUPTCY_THRESHOLD) {
                DB::rollBack();
                errorResponse(sprintf('当前总资产为 ¥%s，未跌破破产阈值 ¥%s，暂不可申请破产救济！',
                    number_format($realTotalAssets, 2), number_format(BANKRUPTCY_THRESHOLD, 2)), 400);
            }

            // 发放破产救济金
            $newBal = round($curBal + BANKRUPTCY_BAILOUT_AMOUNT, 4);
            $stmtUp = $db->prepare('UPDATE users SET balance = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUp->execute(['b' => $newBal, 'id' => $user['id']]);

            // 写入救济记录
            $stmtIns = $db->prepare('INSERT INTO bankruptcy_bailouts (user_id, bailout_amount, total_assets_before) VALUES (:uid, :amt, :before)');
            $stmtIns->execute(['uid' => $user['id'], 'amt' => BANKRUPTCY_BAILOUT_AMOUNT, 'before' => $realTotalAssets]);

            // 写入账目流水
            $stmtL = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, ref_id, description)
                VALUES (:uid, \'BANKRUPTCY_BAILOUT\', :amt, :bal, :lm, \'SYSTEM\', \'BAILOUT\', :desc)
            ');
            $stmtL->execute([
                'uid' => $user['id'],
                'amt' => BANKRUPTCY_BAILOUT_AMOUNT,
                'bal' => $newBal,
                'lm' => $curLock,
                'desc' => sprintf('破产重组低保金 ¥%s 到账 (申请前总资产: ¥%s)', number_format(BANKRUPTCY_BAILOUT_AMOUNT, 2), number_format($realTotalAssets, 2))
            ]);

            // 全服广播破产再起快讯
            $newsTitle = '【破产救助】热血群友浴火重组！';
            $newsContent = sprintf('玩家 [%s] 在激烈角逐中总资产跌至 ¥%s，经赛博救助委员会审核通过，已领走 ¥%s 紧急重组救济金重返股市！',
                $user['username'], number_format($realTotalAssets, 2), number_format(BANKRUPTCY_BAILOUT_AMOUNT, 2));
            
            $stmtEv = $db->prepare("
                INSERT INTO market_events (title, content, category, source_type, impact_factor, duration_sec, is_active, expires_at)
                VALUES (:t, :c, 'RANDOM_MACRO', 'SYSTEM_SIM', 0.01, 300, TRUE, CURRENT_TIMESTAMP + INTERVAL '5 minutes')
            ");
            $stmtEv->execute(['t' => $newsTitle, 'c' => $newsContent]);

            DB::commit();
            jsonResponse([
                'bailout_amount' => BANKRUPTCY_BAILOUT_AMOUNT,
                'balance' => $newBal,
                'total_assets' => round($realTotalAssets + BANKRUPTCY_BAILOUT_AMOUNT, 2)
            ], sprintf('破产重组救济金申请成功！已发放 ¥%s 元到您的可用现金账户。胜败乃兵家常事，祝您东山再起！', number_format(BANKRUPTCY_BAILOUT_AMOUNT, 2)));
        } catch (Exception $e) {
            DB::rollBack();
            errorResponse('破产救助申请失败，请稍后重试或联系管理员', 500);
        }
        break;

    default:
        errorResponse('未知的资产请求', 404);
        break;
}
