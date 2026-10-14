<?php
/**
 * 赛博多人股市 - 全局共享行情与市场引擎
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/short.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/auto.php';

// 仅当本文件为直接请求入口时执行路由；被 worker.php (CLI 守护进程) 引入时只提供市场引擎函数
$isDirectRequest = isApiEntryPoint(__FILE__);

/**
 * 推进市场单个 Tick (服务端统一驱动)
 */
function advanceMarketTick(PDO $db, bool $force = false): array {
    // 锁定市场状态
    $stmtState = $db->prepare('SELECT * FROM market_state WHERE id = 1 FOR UPDATE');
    $stmtState->execute();
    $state = $stmtState->fetch();

    if (!$state) {
        return ['updated' => false, 'reason' => 'market_state_missing'];
    }

    if (filter_var($state['is_paused'], FILTER_VALIDATE_BOOLEAN)) {
        return ['updated' => false, 'reason' => 'paused', 'tick_count' => (int)$state['tick_count']];
    }

    $lastTick = strtotime($state['last_tick_time']);
    $now = time();
    $interval = 2.5; // 每 2.5 秒允许推进一个 Tick

    if (!$force && ($now - $lastTick) < $interval) {
        return ['updated' => false, 'reason' => 'throttled', 'tick_count' => (int)$state['tick_count']];
    }

    // 1. 获取所有有效市场事件的影响因子
    $stmtEvents = $db->query('
        SELECT stock_code, industry, impact_factor 
        FROM market_events 
        WHERE is_active = TRUE AND expires_at > CURRENT_TIMESTAMP
    ');
    $activeEvents = $stmtEvents->fetchAll();
    $eventDrift = [];
    $industryDrift = [];
    foreach ($activeEvents as $ev) {
        if (!empty($ev['stock_code'])) {
            $eventDrift[$ev['stock_code']] = ($eventDrift[$ev['stock_code']] ?? 0) + (float)$ev['impact_factor'];
        }
        if (!empty($ev['industry'])) {
            $industryDrift[$ev['industry']] = ($industryDrift[$ev['industry']] ?? 0) + (float)$ev['impact_factor'];
        }
    }

    // 2. 遍历更新股票行情
    $stmtStocks = $db->query('SELECT * FROM stocks FOR UPDATE');
    $stocks = $stmtStocks->fetchAll();

    $minuteBucket = date('Y-m-d H:i:00');

    $stmtUpStock = $db->prepare('
        UPDATE stocks SET 
            current_price = :cur,
            high_price = GREATEST(high_price, :cur),
            low_price = LEAST(low_price, :cur),
            volume = volume + :vol,
            amount = amount + :amt,
            status = :status,
            circuit_breaker_until = :cb_until,
            updated_at = CURRENT_TIMESTAMP
        WHERE code = :code
    ');

    $stmtCandle = $db->prepare('
        INSERT INTO candles (stock_code, period, candle_time, open, high, low, close, volume, amount)
        VALUES (:code, \'1m\', :time, :price, :price, :price, :price, :vol, :amt)
        ON CONFLICT (stock_code, period, candle_time) DO UPDATE SET
            high = GREATEST(candles.high, EXCLUDED.high),
            low = LEAST(candles.low, EXCLUDED.low),
            close = EXCLUDED.close,
            volume = candles.volume + EXCLUDED.volume,
            amount = candles.amount + EXCLUDED.amount
    ');

    foreach ($stocks as $stock) {
        $code = $stock['code'];
        $curPrice = (float)$stock['current_price'];
        $prevClose = (float)$stock['prev_close'];
        $status = $stock['status'];
        $cbUntil = $stock['circuit_breaker_until'];

        // 检查熔断恢复
        if ($status === 'CIRCUIT_BREAKER') {
            if ($cbUntil && strtotime($cbUntil) <= $now) {
                $status = 'TRADING';
                $cbUntil = null;
            } else {
                continue; // 处于熔断冷却中，价格冻结
            }
        }

        // 计算波动漂移 (布朗随机游走 + 均值回归 + 事件冲击)
        if ($code === '999999') {
            // 彩蛋股票「影视飓风」
            $randRatio = (mt_rand(1, 100) <= 70) 
                ? (mt_rand(20, 150) / 10000.0) 
                : -(mt_rand(20, 100) / 10000.0);
        } else {
            // 普通股票基础随机波动 (-0.6% ~ +0.6%)
            $randRatio = (mt_rand(-60, 60)) / 10000.0;
        }

        // 叠加事件影响
        $drift = ($eventDrift[$code] ?? 0.0) + ($industryDrift[$stock['industry']] ?? 0.0);
        $totalRatio = $randRatio + ($drift * 0.1); // 事件因子分步释放

        $newPrice = round($curPrice * (1.0 + $totalRatio), 2);

        // 涨跌停限制 clamp
        $limitUp = round($prevClose * (1.0 + PRICE_LIMIT_RATE), 2);
        $limitDown = round($prevClose * (1.0 - PRICE_LIMIT_RATE), 2);
        if ($newPrice > $limitUp) {
            $newPrice = $limitUp;
        }
        if ($newPrice < $limitDown) {
            $newPrice = $limitDown;
        }

        // 熔断触发检测 (单Tick波动超过 5%)
        $tickSwing = abs($newPrice - $curPrice) / max(0.01, $curPrice);
        if ($tickSwing >= CIRCUIT_THRESHOLD) {
            $status = 'CIRCUIT_BREAKER';
            $cbUntil = date('Y-m-d H:i:s', $now + 10); // 冷却 10 秒
        }

        // 基础模拟成交量
        $simVol = (int)(mt_rand(50, 400) * 100);
        $simAmt = round($simVol * $newPrice, 2);

        $stmtUpStock->execute([
            'cur' => $newPrice,
            'vol' => $simVol,
            'amt' => $simAmt,
            'status' => $status,
            'cb_until' => $cbUntil,
            'code' => $code
        ]);

        $stmtCandle->execute([
            'code' => $code,
            'time' => $minuteBucket,
            'price' => $newPrice,
            'vol' => $simVol,
            'amt' => $simAmt
        ]);
    }

    // 3. 更新市场状态全局计数
    $newTick = (int)$state['tick_count'] + 1;
    $stmtUpState = $db->prepare('UPDATE market_state SET tick_count = :cnt, last_tick_time = CURRENT_TIMESTAMP WHERE id = 1');
    $stmtUpState->execute(['cnt' => $newTick]);

    // 4. 触发空头持仓维持保证金巡检与自动强平
    executeLiquidationCheck($db);

    // 5. 触发玩家交易异动事件检测
    evaluateMarketEvents($db);

    // 6. 触发自动交易策略引擎
    evaluateAutoStrategies($db);

    return ['updated' => true, 'tick_count' => $newTick];
}

// 路由分发 (仅直接请求本文件时执行)
if ($isDirectRequest) {
$action = $_GET['action'] ?? 'quotes';
$db = DB::getConnection();

switch ($action) {
    case 'quotes':
        // 行情轮询，顺带执行防饿死 Tick
        DB::beginTransaction();
        try {
            advanceMarketTick($db, false);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            error_log('Auto tick error: ' . $e->getMessage());
        }

        // 全服玩家人数与资本扩容乘数
        $playerCount = max(1, (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn());
        $totalCapital = (float)$db->query("SELECT COALESCE(SUM(balance + locked_margin), 0) FROM users")->fetchColumn();
        $capitalMultiplier = max(1.0, round(1.0 + ($totalCapital - 2000000.0) / 10000000.0, 2));

        $stmt = $db->query('
            SELECT 
                code, name, industry, current_price, prev_close, open_price, high_price, low_price, 
                volume, amount, status, unlock_required_users, total_shares, max_short_shares,
                ROUND((current_price - prev_close), 2) AS change_val,
                ROUND(((current_price - prev_close) / prev_close * 100.0), 2) AS change_percent,
                ROUND((prev_close * (1 + ' . PRICE_LIMIT_RATE . ')), 2) AS limit_up,
                ROUND((prev_close * (1 - ' . PRICE_LIMIT_RATE . ')), 2) AS limit_down
            FROM stocks
            ORDER BY 
                CASE WHEN code = \'999999\' THEN 0 ELSE 1 END,
                amount DESC, code ASC
        ');
        $quotes = $stmt->fetchAll();

        // 格式化数值字段
        $formatted = array_map(function($item) use ($playerCount, $capitalMultiplier) {
            return [
                'code' => $item['code'],
                'name' => $item['name'],
                'industry' => $item['industry'],
                'current_price' => (float)$item['current_price'],
                'prev_close' => (float)$item['prev_close'],
                'open_price' => (float)$item['open_price'],
                'high_price' => (float)$item['high_price'],
                'low_price' => (float)$item['low_price'],
                'volume' => (int)$item['volume'],
                'amount' => (float)$item['amount'],
                'status' => $item['status'],
                'change' => (float)$item['change_val'],
                'change_percent' => (float)$item['change_percent'],
                'limit_up' => (float)$item['limit_up'],
                'limit_down' => (float)$item['limit_down'],
                'unlock_required_users' => (int)($item['unlock_required_users'] ?? 1),
                'is_unlocked' => ((int)($item['unlock_required_users'] ?? 1) <= $playerCount),
                'effective_total_shares' => (int)(($item['total_shares'] ?? 200000) * $capitalMultiplier),
                'effective_max_short' => (int)(($item['max_short_shares'] ?? 60000) * $capitalMultiplier),
            ];
        }, $quotes);

        jsonResponse([
            'quotes' => $formatted,
            'count' => count($formatted),
            'player_count' => $playerCount,
            'capital_multiplier' => $capitalMultiplier,
            'total_market_capital' => $totalCapital
        ]);
        break;

    case 'detail':
        $code = trim($_GET['code'] ?? '');
        if (empty($code)) {
            errorResponse('请提供股票代码');
        }

        $stmt = $db->prepare('
            SELECT 
                code, name, industry, current_price, prev_close, open_price, high_price, low_price, 
                volume, amount, liquidity_depth, total_shares, max_short_shares, unlock_required_users, status, circuit_breaker_until,
                ROUND((current_price - prev_close), 2) AS change_val,
                ROUND(((current_price - prev_close) / prev_close * 100.0), 2) AS change_percent,
                ROUND((prev_close * (1 + ' . PRICE_LIMIT_RATE . ')), 2) AS limit_up,
                ROUND((prev_close * (1 - ' . PRICE_LIMIT_RATE . ')), 2) AS limit_down
            FROM stocks
            WHERE code = :code
        ');
        $stmt->execute(['code' => $code]);
        $stock = $stmt->fetch();

        if (!$stock) {
            errorResponse('未找到指定的股票: ' . htmlspecialchars($code), 404);
        }

        $cur = (float)$stock['current_price'];
        $prev = (float)$stock['prev_close'];
        $lUp = (float)$stock['limit_up'];
        $lDown = (float)$stock['limit_down'];

        // 生成规范的五档买卖盘 (Order Book)
        $bids = [];
        $asks = [];
        for ($i = 1; $i <= 5; $i++) {
            $bidPrice = max($lDown, round($cur - $i * 0.01, 2));
            $askPrice = min($lUp, round($cur + $i * 0.01, 2));
            $bidVol = (int)(mt_rand(20, 300) * 100);
            $askVol = (int)(mt_rand(20, 300) * 100);
            $bids[] = ['level' => '买' . $i, 'price' => $bidPrice, 'volume' => $bidVol];
            $asks[] = ['level' => '卖' . $i, 'price' => $askPrice, 'volume' => $askVol];
        }

        // 查询当前股票生效中的事件
        $stmtEv = $db->prepare('
            SELECT id, title, content, category, impact_factor, created_at, expires_at 
            FROM market_events 
            WHERE is_active = TRUE AND expires_at > CURRENT_TIMESTAMP 
              AND (stock_code = :c OR industry = :ind)
            ORDER BY created_at DESC LIMIT 5
        ');
        $stmtEv->execute(['c' => $code, 'ind' => $stock['industry']]);
        $events = $stmtEv->fetchAll();

        // 持仓统计 (参数化查询，避免拼接)
        $stmtHeld = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :c AND position_type = 'LONG'");
        $stmtHeld->execute(['c' => $code]);
        $heldShares = (int)$stmtHeld->fetchColumn();

        $stmtBorrowed = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :c AND position_type = 'SHORT'");
        $stmtBorrowed->execute(['c' => $code]);
        $borrowedShortShares = (int)$stmtBorrowed->fetchColumn();

        jsonResponse([
            'stock' => [
                'code' => $stock['code'],
                'name' => $stock['name'],
                'industry' => $stock['industry'],
                'current_price' => $cur,
                'prev_close' => $prev,
                'open_price' => (float)$stock['open_price'],
                'high_price' => (float)$stock['high_price'],
                'low_price' => (float)$stock['low_price'],
                'volume' => (int)$stock['volume'],
                'amount' => (float)$stock['amount'],
                'status' => $stock['status'],
                'change' => (float)$stock['change_val'],
                'change_percent' => (float)$stock['change_percent'],
                'limit_up' => $lUp,
                'limit_down' => $lDown,
                'order_book' => [
                    'bids' => $bids,
                    'asks' => array_reverse($asks) // 卖五到卖一呈现
                ],
                'active_events' => $events,
                'total_shares' => (int)($stock['total_shares'] ?? 200000),
                'max_short_shares' => (int)($stock['max_short_shares'] ?? 60000),
                'unlock_required_users' => (int)($stock['unlock_required_users'] ?? 1),
                'is_unlocked' => ((int)($stock['unlock_required_users'] ?? 1) <= (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn()),
                'held_shares' => $heldShares,
                'borrowed_short_shares' => $borrowedShortShares
            ]
        ]);
        break;

    case 'sentiment':
        // 全服筹码锁仓与融券多空情绪接口
        $code = trim($_GET['code'] ?? '');
        if (!empty($code)) {
            $stmt = $db->prepare('SELECT code, name, current_price, total_shares, max_short_shares FROM stocks WHERE code = :code');
            $stmt->execute(['code' => $code]);
            $s = $stmt->fetch();
            if (!$s) {
                errorResponse('股票不存在', 404);
            }
            $stocksList = [$s];
        } else {
            $stmt = $db->query('SELECT code, name, current_price, total_shares, max_short_shares FROM stocks ORDER BY amount DESC LIMIT 30');
            $stocksList = $stmt->fetchAll();
        }

        $sentimentData = [];
        foreach ($stocksList as $s) {
            $sc = $s['code'];
            $tot = (int)($s['total_shares'] ?? 200000);
            $maxS = (int)($s['max_short_shares'] ?? 60000);

            $stmtL = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :c AND position_type = 'LONG'");
            $stmtL->execute(['c' => $sc]);
            $held = (int)$stmtL->fetchColumn();

            $stmtSh = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM positions WHERE stock_code = :c AND position_type = 'SHORT'");
            $stmtSh->execute(['c' => $sc]);
            $borrowed = (int)$stmtSh->fetchColumn();

            $avail = max(0, $tot - $held);
            $availSh = max(0, $maxS - $borrowed);
            $lockup = round(($held / max(1, $tot)) * 100.0, 2);
            $shortUtil = round(($borrowed / max(1, $maxS)) * 100.0, 2);

            $riskLevel = 'NORMAL';
            if ($shortUtil > 70 && $lockup > 60) {
                $riskLevel = 'EXTREME_SQUEEZE'; // 极高逼空爆仓风险
            } elseif ($shortUtil > 50 || $lockup > 50) {
                $riskLevel = 'HIGH_SQUEEZE';
            }

            $sentimentData[] = [
                'code' => $sc,
                'name' => $s['name'],
                'current_price' => (float)$s['current_price'],
                'total_shares' => $tot,
                'held_shares' => $held,
                'available_shares' => $avail,
                'lockup_ratio' => $lockup,
                'max_short_shares' => $maxS,
                'borrowed_short_shares' => $borrowed,
                'available_short_shares' => $availSh,
                'short_utilization' => $shortUtil,
                'is_short_exhausted' => ($availSh <= 0),
                'is_fully_locked' => ($avail <= 0),
                'squeeze_risk' => $riskLevel
            ];
        }

        jsonResponse([
            'sentiment' => empty($code) ? $sentimentData : ($sentimentData[0] ?? null)
        ]);
        break;

    case 'candles':
        $code = trim($_GET['code'] ?? '');
        $period = trim($_GET['period'] ?? '1m');
        $limit = max(10, min(300, (int)($_GET['limit'] ?? 60)));

        if (empty($code)) {
            errorResponse('请提供股票代码');
        }

        $stmt = $db->prepare('
            SELECT 
                TO_CHAR(candle_time, \'YYYY-MM-DD HH24:MI\') AS time_str,
                open, high, low, close, volume, amount
            FROM candles
            WHERE stock_code = :code AND period = :p
            ORDER BY candle_time DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':code', $code);
        $stmt->bindValue(':p', $period);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());

        $candles = array_map(function($r) {
            return [
                'time' => $r['time_str'],
                'open' => (float)$r['open'],
                'high' => (float)$r['high'],
                'low' => (float)$r['low'],
                'close' => (float)$r['close'],
                'volume' => (int)$r['volume'],
                'amount' => (float)$r['amount']
            ];
        }, $rows);

        jsonResponse([
            'stock_code' => $code,
            'period' => $period,
            'candles' => $candles
        ]);
        break;

    case 'trades':
        $code = trim($_GET['code'] ?? '');
        $limit = max(5, min(100, (int)($_GET['limit'] ?? 30)));

        if (!empty($code)) {
            $stmt = $db->prepare('
                SELECT t.id, t.stock_code, s.name AS stock_name, t.direction, t.price, t.quantity, t.amount, 
                       TO_CHAR(t.created_at, \'HH24:MI:SS\') AS trade_time,
                       u.username
                FROM trades t
                JOIN stocks s ON t.stock_code = s.code
                JOIN users u ON t.user_id = u.id
                WHERE t.stock_code = :code
                ORDER BY t.created_at DESC LIMIT :limit
            ');
            $stmt->bindValue(':code', $code);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('
                SELECT t.id, t.stock_code, s.name AS stock_name, t.direction, t.price, t.quantity, t.amount, 
                       TO_CHAR(t.created_at, \'HH24:MI:SS\') AS trade_time,
                       u.username
                FROM trades t
                JOIN stocks s ON t.stock_code = s.code
                JOIN users u ON t.user_id = u.id
                ORDER BY t.created_at DESC LIMIT :limit
            ');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        $trades = $stmt->fetchAll();

        $formatted = array_map(function($t) {
            // 匿名化玩家名字，例如 "炒股老王" -> "炒**王"
            $un = $t['username'];
            $len = mb_strlen($un);
            $maskedUser = $len > 2 ? mb_substr($un, 0, 1) . '**' . mb_substr($un, -1) : $un;

            return [
                'id' => (int)$t['id'],
                'stock_code' => $t['stock_code'],
                'stock_name' => $t['stock_name'],
                'direction' => $t['direction'],
                'price' => (float)$t['price'],
                'quantity' => (int)$t['quantity'],
                'amount' => (float)$t['amount'],
                'time' => $t['trade_time'],
                'user' => $maskedUser
            ];
        }, $trades);

        jsonResponse(['trades' => $formatted]);
        break;

    case 'overview':
        $stmtState = $db->query('SELECT current_day, tick_count, is_paused, trading_phase, last_tick_time FROM market_state WHERE id = 1');
        $mState = $stmtState->fetch();

        $stmtStat = $db->query('
            SELECT 
                COUNT(*) FILTER (WHERE current_price > prev_close) AS up_count,
                COUNT(*) FILTER (WHERE current_price < prev_close) AS down_count,
                COUNT(*) FILTER (WHERE current_price = prev_close) AS flat_count,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(volume), 0) AS total_volume
            FROM stocks
        ');
        $stat = $stmtStat->fetch();

        jsonResponse([
            'market_state' => [
                'day' => (int)$mState['current_day'],
                'tick_count' => (int)$mState['tick_count'],
                'is_paused' => filter_var($mState['is_paused'], FILTER_VALIDATE_BOOLEAN),
                'trading_phase' => $mState['trading_phase'],
                'last_tick_time' => $mState['last_tick_time']
            ],
            'statistics' => [
                'up_count' => (int)$stat['up_count'],
                'down_count' => (int)$stat['down_count'],
                'flat_count' => (int)$stat['flat_count'],
                'total_amount' => (float)$stat['total_amount'],
                'total_volume' => (int)$stat['total_volume']
            ]
        ]);
        break;

    case 'tick':
        // 安全: 强制推进市场属管理操作 (force=true 会绕过节流), 仅管理员可调用
        $admin = requireAdmin();
        DB::beginTransaction();
        try {
            $res = advanceMarketTick($db, true);
            DB::commit();

            // 审计
            $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'FORCE_TICK\', \'MARKET\', \'1\', :d)');
            $stmtLog->execute(['a' => $admin['id'], 'd' => json_encode(['tick_count' => $res['tick_count'] ?? 0])]);

            jsonResponse($res, 'Tick executed successfully');
        } catch (Exception $e) {
            DB::rollBack();
            error_log('Tick failed: ' . $e->getMessage());
            errorResponse('市场 Tick 推进失败，请稍后重试', 500);
        }
        break;

    default:
        errorResponse('未知的行情请求', 404);
        break;
}
}
