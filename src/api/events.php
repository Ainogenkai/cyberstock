<?php
/**
 * 赛博多人股市 - 玩家行为驱动事件系统与现实关联模拟
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// 仅当本文件为直接请求入口时执行路由；被 market.php 引入时只提供事件引擎函数
$isDirectRequest = isApiEntryPoint(__FILE__);

/**
 * 预置现实关联与系统模拟新闻事件候选池
 */
function getSystemNewsTemplates(): array {
    return [
        [
            'title' => '【宏观要闻】央行释放积极货币政策信号，市场流动性预期充裕',
            'content' => '央行今日表态将综合运用多种货币政策工具，保持流动性合理充裕。大金融与基建板块资金关注度显著上升。',
            'category' => 'POLICY_NEWS',
            'industry' => '商业银行',
            'impact_factor' => 0.025,
            'duration' => 240
        ],
        [
            'title' => '【硬核科技】全自主先进制程半导体产线顺利点亮，芯片自主可控再提速',
            'content' => '国家级科技专项取得阶段性关键成果，本土半导体供应链良品率大幅超出市场预期，芯片及设备龙头全线受益。',
            'category' => 'TECH_BREAKTHROUGH',
            'industry' => '半导体芯片',
            'impact_factor' => 0.035,
            'duration' => 300
        ],
        [
            'title' => '【绿色能源】固态动力电池完成全尺寸实车耐久测试，续航突破新纪录',
            'content' => '头部新能源电池厂商宣布下一代高能量密度固态电池正式进入试产阶段，新能源汽车整车与电池产业链迎来估值重塑。',
            'category' => 'TECH_BREAKTHROUGH',
            'industry' => '新能源电池',
            'impact_factor' => 0.030,
            'duration' => 240
        ],
        [
            'title' => '【消费复苏】传统节日旺季批价企稳回升，高端消费需求韧性彰显',
            'content' => '渠道调研显示高端白酒动销好于悲观预期，回款进度符合旺季节奏，白酒龙头企业现金流充沛展现极高抗周期属性。',
            'category' => 'INDUSTRY_RUMOR',
            'industry' => '白酒消费',
            'impact_factor' => 0.020,
            'duration' => 200
        ],
        [
            'title' => '【医药创新】首创靶向抗癌新药获国家药监局附条件批准上市',
            'content' => '国内药企自主研发的创新药临床三期试验达到主要终点并加速获批，海外授权许可收入有望进入爆发期。',
            'category' => 'TECH_BREAKTHROUGH',
            'industry' => '生物医药',
            'impact_factor' => 0.030,
            'duration' => 240
        ],
        [
            'title' => '【海外扰动】国际地缘摩擦升级引发大宗商品震荡，避险贵金属与能源走高',
            'content' => '受海外地缘局势变化影响，国际原油与黄金期货出现剧烈波动，上游采掘与有色金属板块避险买盘涌现。',
            'category' => 'RANDOM_MACRO',
            'industry' => '有色金属',
            'impact_factor' => 0.025,
            'duration' => 240
        ]
    ];
}

/**
 * 评估玩家群体行为并触发驱动事件
 */
function evaluateMarketEvents(PDO $db): void {
    // 1. 检查最近 120 秒内成交最活跃的股票行为
    $stmtTradeAgg = $db->query('
        SELECT 
            t.stock_code, s.name AS stock_name, s.industry,
            COUNT(*) AS trade_count,
            SUM(CASE WHEN t.direction = \'BUY\' THEN t.quantity ELSE 0 END) AS buy_volume,
            SUM(CASE WHEN t.direction = \'SELL\' THEN t.quantity ELSE 0 END) AS sell_volume,
            SUM(CASE WHEN t.direction = \'SHORT_OPEN\' THEN t.quantity ELSE 0 END) AS short_open_volume
        FROM trades t
        JOIN stocks s ON t.stock_code = s.code
        WHERE t.created_at >= CURRENT_TIMESTAMP - INTERVAL \'120 seconds\'
        GROUP BY t.stock_code, s.name, s.industry
        HAVING COUNT(*) >= 2
    ');
    $activities = $stmtTradeAgg->fetchAll();

    foreach ($activities as $act) {
        $code = $act['stock_code'];
        $name = $act['stock_name'];
        $buyVol = (int)$act['buy_volume'];
        $sellVol = (int)$act['sell_volume'];
        $shortVol = (int)$act['short_open_volume'];

        // 玩家集体狂热做多买入
        if ($buyVol >= 2000 && $buyVol > ($sellVol + $shortVol) * 2) {
            // 防重复触发检查 (过去 5 分钟内同一股票同类事件不重复触发)
            $stmtChk = $db->prepare('
                SELECT id FROM market_events 
                WHERE stock_code = :c AND category = \'MARKET_SURGE\' AND created_at >= CURRENT_TIMESTAMP - INTERVAL \'300 seconds\'
            ');
            $stmtChk->execute(['c' => $code]);
            if (!$stmtChk->fetch()) {
                $stmtIns = $db->prepare('
                    INSERT INTO market_events (title, content, category, source_type, stock_code, industry, impact_factor, duration_sec, expires_at)
                    VALUES (:t, :c, \'MARKET_SURGE\', \'PLAYER_ACTION\', :code, :ind, 0.03, 180, CURRENT_TIMESTAMP + INTERVAL \'180 seconds\')
                ');
                $stmtIns->execute([
                    't' => sprintf('玩家异动：群友集中扫货【%s】！', $name),
                    'c' => sprintf('检测到多名群友短时间内向 %s (%s) 集中注入大量多头买单 (累计 %d 股)，资金合力引发盘面剧烈拉升！', $name, $code, $buyVol),
                    'code' => $code,
                    'ind' => $act['industry']
                ]);
            }
        }

        // 玩家集体开空围猎
        if ($shortVol >= 2000) {
            $stmtChk = $db->prepare('
                SELECT id FROM market_events 
                WHERE stock_code = :c AND category = \'SHORT_SQUEEZE\' AND created_at >= CURRENT_TIMESTAMP - INTERVAL \'300 seconds\'
            ');
            $stmtChk->execute(['c' => $code]);
            if (!$stmtChk->fetch()) {
                $stmtIns = $db->prepare('
                    INSERT INTO market_events (title, content, category, source_type, stock_code, industry, impact_factor, duration_sec, expires_at)
                    VALUES (:t, :c, \'SHORT_SQUEEZE\', \'PLAYER_ACTION\', :code, :ind, -0.03, 180, CURRENT_TIMESTAMP + INTERVAL \'180 seconds\')
                ');
                $stmtIns->execute([
                    't' => sprintf('空头围猎：群友联合开空【%s】！', $name),
                    'c' => sprintf('市场出现大量做空融券盘，累计开空高达 %d 股，%s (%s) 盘中面临强大空头打压压力！', $shortVol, $name, $code),
                    'code' => $code,
                    'ind' => $act['industry']
                ]);
            }
        }
    }

    // 2. 检查是否有熔断股票触发告警
    $stmtCb = $db->query('SELECT code, name, industry FROM stocks WHERE status = \'CIRCUIT_BREAKER\'');
    $cbStocks = $stmtCb->fetchAll();
    foreach ($cbStocks as $cb) {
        $stmtChk = $db->prepare('
            SELECT id FROM market_events 
            WHERE stock_code = :c AND category = \'VOLATILITY_HALT\' AND created_at >= CURRENT_TIMESTAMP - INTERVAL \'180 seconds\'
        ');
        $stmtChk->execute(['c' => $cb['code']]);
        if (!$stmtChk->fetch()) {
            $stmtIns = $db->prepare('
                INSERT INTO market_events (title, content, category, source_type, stock_code, industry, impact_factor, duration_sec, expires_at)
                VALUES (:t, :c, \'VOLATILITY_HALT\', \'SYSTEM_SIM\', :code, :ind, 0.00, 60, CURRENT_TIMESTAMP + INTERVAL \'60 seconds\')
            ');
            $stmtIns->execute([
                't' => sprintf('交易所提示：【%s】触发临时波动熔断！', $cb['name']),
                'c' => sprintf('股票 %s (%s) 单Tick波动达到临界值，自动进入熔断冷静期，暂停撮合交易。', $cb['name'], $cb['code']),
                'code' => $cb['code'],
                'ind' => $cb['industry']
            ]);
        }
    }

    // 3. 低概率触发系统现实关联/宏观资讯 (若当前没有有效系统事件且投掷中)
    $stmtActSys = $db->query('
        SELECT COUNT(*) FROM market_events 
        WHERE is_active = TRUE AND source_type IN (\'SYSTEM_SIM\', \'REAL_MAPPING\') AND expires_at > CURRENT_TIMESTAMP
    ');
    $sysCount = (int)$stmtActSys->fetchColumn();

    if ($sysCount === 0 && mt_rand(1, 100) <= 8) { // 8% 概率生成系统宏观事件
        $templates = getSystemNewsTemplates();
        $item = $templates[array_rand($templates)];

        $stmtIns = $db->prepare('
            INSERT INTO market_events (title, content, category, source_type, industry, impact_factor, duration_sec, expires_at)
            VALUES (:t, :c, :cat, \'REAL_MAPPING\', :ind, :factor, :dur, CURRENT_TIMESTAMP + make_interval(secs => :dur_int))
        ');
        $stmtIns->execute([
            't' => $item['title'],
            'c' => $item['content'],
            'cat' => $item['category'],
            'ind' => $item['industry'],
            'factor' => $item['impact_factor'],
            'dur' => $item['duration'],
            'dur_int' => (int)$item['duration']
        ]);
    }
}

// 路由处理 (仅直接请求本文件时执行)
if ($isDirectRequest) {
$action = $_GET['action'] ?? 'list';
$db = DB::getConnection();

switch ($action) {
    case 'list':
        $limit = max(5, min(50, (int)($_GET['limit'] ?? 20)));
        $stmt = $db->prepare('
            SELECT 
                id, title, content, category, source_type, stock_code, industry, impact_factor, 
                duration_sec, is_active,
                (expires_at > CURRENT_TIMESTAMP AND is_active = TRUE) AS is_currently_active,
                TO_CHAR(created_at, \'YYYY-MM-DD HH24:MI:SS\') AS created_time,
                TO_CHAR(expires_at, \'YYYY-MM-DD HH24:MI:SS\') AS expires_time
            FROM market_events
            ORDER BY created_at DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll();

        $formatted = array_map(function($ev) {
            return [
                'id' => (int)$ev['id'],
                'title' => $ev['title'],
                'content' => $ev['content'],
                'category' => $ev['category'],
                'source_type' => $ev['source_type'],
                'stock_code' => $ev['stock_code'],
                'industry' => $ev['industry'],
                'impact_factor' => (float)$ev['impact_factor'],
                'duration_sec' => (int)$ev['duration_sec'],
                'is_active' => filter_var($ev['is_currently_active'], FILTER_VALIDATE_BOOLEAN),
                'created_at' => $ev['created_time'],
                'expires_at' => $ev['expires_time']
            ];
        }, $events);

        jsonResponse(['events' => $formatted]);
        break;

    case 'create':
        // 管理员发布突发新闻/宏观事件
        $admin = requireAdmin();
        $input = getJsonInput();

        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? 'POLICY_NEWS');
        $stockCode = trim($input['stock_code'] ?? '') ?: null;
        $industry = trim($input['industry'] ?? '') ?: null;
        $impactFactor = (float)($input['impact_factor'] ?? 0.0);
        $durationSec = max(30, min(1800, (int)($input['duration_sec'] ?? 300)));

        if (empty($title) || empty($content)) {
            errorResponse('请输入事件标题和详情正文');
        }

        $stmtIns = $db->prepare('
            INSERT INTO market_events (title, content, category, source_type, stock_code, industry, impact_factor, duration_sec, expires_at)
            VALUES (:t, :c, :cat, \'ADMIN\', :code, :ind, :factor, :dur, CURRENT_TIMESTAMP + make_interval(secs => :dur_int))
            RETURNING id
        ');
        $stmtIns->execute([
            't' => $title,
            'c' => $content,
            'cat' => $category,
            'code' => $stockCode,
            'ind' => $industry,
            'factor' => $impactFactor,
            'dur' => $durationSec,
            'dur_int' => $durationSec
        ]);
        $evId = $stmtIns->fetchColumn();

        // 记录管理操作审计
        $stmtLog = $db->prepare('
            INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json, ip_address)
            VALUES (:aid, \'CREATE_MARKET_EVENT\', \'EVENT\', :tid, :details, :ip)
        ');
        $stmtLog->execute([
            'aid' => $admin['id'],
            'tid' => (string)$evId,
            'details' => json_encode(['title' => $title, 'factor' => $impactFactor, 'duration' => $durationSec], JSON_UNESCAPED_UNICODE),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        jsonResponse(['event_id' => (int)$evId], '市场事件已发布并生效');
        break;

    case 'close':
        // 管理员提前关闭事件
        $admin = requireAdmin();
        $input = getJsonInput();
        $eventId = (int)($input['event_id'] ?? 0);

        if ($eventId <= 0) {
            errorResponse('请提供合法的事件 ID');
        }

        $stmtUp = $db->prepare('UPDATE market_events SET is_active = FALSE WHERE id = :id');
        $stmtUp->execute(['id' => $eventId]);

        // 记录审计
        $stmtLog = $db->prepare('
            INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json, ip_address)
            VALUES (:aid, \'CLOSE_MARKET_EVENT\', \'EVENT\', :tid, :details, :ip)
        ');
        $stmtLog->execute([
            'aid' => $admin['id'],
            'tid' => (string)$eventId,
            'details' => json_encode(['action' => 'close_event'], JSON_UNESCAPED_UNICODE),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        jsonResponse(null, '事件已成功关闭');
        break;

    case 'broadcast':
        // 登录用户全服跑马灯广播 (供 MCP 操盘手等使用，零市场冲击)
        // 与管理员 create 的区别：无需 admin 权限，但服务端限频且不改变行情因子
        $user = requireAuth();
        $input = getJsonInput();

        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $durationSec = max(30, min(1800, (int)($input['duration_sec'] ?? 180)));

        if ($title === '' || $content === '') {
            errorResponse('请输入公告标题与正文');
        }
        if (mb_strlen($title) > 60 || mb_strlen($content) > 200) {
            errorResponse('公告标题最长 60 字，正文最长 200 字');
        }

        DB::beginTransaction();
        try {
            // 服务端限频：同一用户 60 秒内仅允许发布 1 条广播
            $stmtRate = $db->prepare('
                SELECT id FROM bot_broadcasts
                WHERE user_id = :uid AND created_at >= CURRENT_TIMESTAMP - INTERVAL \'60 seconds\'
                FOR UPDATE
            ');
            $stmtRate->execute(['uid' => $user['id']]);
            if ($stmtRate->fetch()) {
                DB::rollBack();
                errorResponse('广播发送太频繁，请稍后再试 (每分钟限 1 条)', 429);
            }

            $stmtRateIns = $db->prepare('INSERT INTO bot_broadcasts (user_id, title) VALUES (:uid, :t)');
            $stmtRateIns->execute(['uid' => $user['id'], 't' => $title]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            error_log('Broadcast throttle error: ' . $e->getMessage());
            errorResponse('广播发布失败', 500);
        }

        // 以跑马灯事件呈现：影响因子固定为 0，纯公告不扰动行情
        $stmtEv = $db->prepare('
            INSERT INTO market_events (title, content, category, source_type, impact_factor, duration_sec, is_active, expires_at)
            VALUES (:t, :c, \'INDUSTRY_RUMOR\', \'BOT_BROADCAST\', 0.00, :dur, TRUE, CURRENT_TIMESTAMP + make_interval(secs => :dur_int))
        ');
        $stmtEv->execute(['t' => $title, 'c' => $content, 'dur' => $durationSec, 'dur_int' => $durationSec]);

        jsonResponse(['title' => $title], '广播已发布到全服跑马灯');
        break;

    default:
        errorResponse('未知的事件请求', 404);
        break;
}
}
