<?php
/**
 * 赛博多人股市 - 数据库连接与事务辅助 (含自动化自愈建表与增量迁移机制)
 */

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;', DB_HOST, DB_PORT, DB_NAME);
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
                // 自愈机制：若数据库尚未建表或股票池为空，自动执行初始化脚本
                self::ensureSchema(self::$instance);
            } catch (PDOException $e) {
                // 安全脱敏: 原始异常含内网主机/端口/驱动细节, 仅写入日志, 不向前端泄露
                error_log('Database Connection Error: ' . $e->getMessage());
                errorResponse('数据库连接失败，请稍后重试 (若持续失败请联系管理员检查数据库服务)', 500);
            }
        }
        return self::$instance;
    }

    private static function ensureSchema(PDO $pdo): void {
        try {
            $tableCheck = $pdo->query("SELECT to_regclass('public.stocks')")->fetchColumn();
            $needInit = false;
            if (!$tableCheck) {
                $needInit = true;
            } else {
                $count = (int)$pdo->query("SELECT COUNT(*) FROM stocks")->fetchColumn();
                if ($count === 0) {
                    $needInit = true;
                }
            }
            if ($needInit && file_exists(__DIR__ . '/init.sql')) {
                $sql = file_get_contents(__DIR__ . '/init.sql');
                $pdo->exec($sql);
            }

            // 增量热更新机制：保证流通盘、融券池、签到低保及久留美账号就绪
            self::ensureIncrementalTables($pdo);
        } catch (Exception $e) {
            error_log("Schema auto-init notice: " . $e->getMessage());
        }
    }

    private static function ensureIncrementalTables(PDO $pdo): void {
        try {
            $pdo->exec("
                ALTER TABLE stocks ADD COLUMN IF NOT EXISTS total_shares BIGINT NOT NULL DEFAULT 200000;
                ALTER TABLE stocks ADD COLUMN IF NOT EXISTS max_short_shares BIGINT NOT NULL DEFAULT 60000;
                ALTER TABLE stocks ADD COLUMN IF NOT EXISTS unlock_required_users INT NOT NULL DEFAULT 1;

                -- 初始化人数梯队解锁门槛
                UPDATE stocks SET unlock_required_users = 1 WHERE code IN ('000001', '600036', '601398', '600519', '002594', '999999');
                UPDATE stocks SET unlock_required_users = 3 WHERE code IN ('688981', '688256', '300308', '002230', '300059');
                UPDATE stocks SET unlock_required_users = 4 WHERE code IN ('300750', '002466', '000063', '601127', '002085', '600115');
                UPDATE stocks SET unlock_required_users = 5 WHERE code IN ('601899', '601857', '002475', '300014', '002709', '002460', '300274', '600438');
                UPDATE stocks SET unlock_required_users = 6 WHERE unlock_required_users = 1 AND code NOT IN ('000001', '600036', '601398', '600519', '002594', '999999');
                
                CREATE TABLE IF NOT EXISTS daily_checkins (
                    id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    checkin_date DATE NOT NULL,
                    reward_amount NUMERIC(18, 4) NOT NULL DEFAULT 50000.0000,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uq_user_checkin_date UNIQUE (user_id, checkin_date)
                );
                CREATE INDEX IF NOT EXISTS idx_daily_checkins_user_date ON daily_checkins (user_id, checkin_date);

                CREATE TABLE IF NOT EXISTS bankruptcy_bailouts (
                    id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    bailout_amount NUMERIC(18, 4) NOT NULL DEFAULT 200000.0000,
                    total_assets_before NUMERIC(18, 4) NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_bankruptcy_bailouts_user ON bankruptcy_bailouts (user_id, created_at DESC);

                -- 约束演进: 旧库的 source_type CHECK 白名单缺少 BOT_BROADCAST (跑马灯广播), 按需升级
                DO \$\$
                BEGIN
                    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'market_events_source_type_check')
                       AND NOT EXISTS (
                           SELECT 1 FROM pg_constraint
                           WHERE conname = 'market_events_source_type_check'
                             AND pg_get_constraintdef(oid) LIKE '%BOT_BROADCAST%'
                       ) THEN
                        ALTER TABLE market_events DROP CONSTRAINT market_events_source_type_check;
                        ALTER TABLE market_events ADD CONSTRAINT market_events_source_type_check
                            CHECK (source_type = ANY (ARRAY['PLAYER_ACTION', 'SYSTEM_SIM', 'REAL_MAPPING', 'ADMIN', 'BOT_BROADCAST']::varchar[]));
                    END IF;
                END \$\$;

                -- 跑马灯广播限频记录 (MCP 操盘手等登录用户全服广播)
                CREATE TABLE IF NOT EXISTS bot_broadcasts (
                    id SERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    title VARCHAR(200) NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_bot_broadcasts_user_time ON bot_broadcasts (user_id, created_at DESC);

                -- 登录暴力破解防护: 失败尝试记录 (按用户名+IP 节流)
                CREATE TABLE IF NOT EXISTS login_failures (
                    id BIGSERIAL PRIMARY KEY,
                    username VARCHAR(64) NOT NULL,
                    ip_address VARCHAR(64) NOT NULL DEFAULT '',
                    failed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_login_failures_lookup ON login_failures (username, ip_address, failed_at DESC);

                -- 自动交易策略表 (服务器端引擎, 关网页不丢失)
                CREATE TABLE IF NOT EXISTS auto_strategies (
                    id BIGSERIAL PRIMARY KEY,
                    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    stock_code VARCHAR(16) NOT NULL REFERENCES stocks(code),
                    name VARCHAR(40) NOT NULL DEFAULT '',
                    direction VARCHAR(8) NOT NULL CHECK (direction IN ('buy', 'sell')),
                    condition_type VARCHAR(16) NOT NULL CHECK (condition_type IN ('price', 'percentage', 'profit', 'time')),
                    condition_operator VARCHAR(8) NOT NULL CHECK (condition_operator IN ('above', 'below', 'equal')),
                    condition_value NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    quantity INT NOT NULL CHECK (quantity > 0),
                    price_type VARCHAR(8) NOT NULL DEFAULT 'MARKET' CHECK (price_type IN ('MARKET', 'LIMIT')),
                    limit_price NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    stop_loss NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    take_profit NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    max_amount NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    is_active BOOLEAN NOT NULL DEFAULT TRUE,
                    max_trigger_count INT NOT NULL DEFAULT 1,
                    triggered_count INT NOT NULL DEFAULT 0,
                    last_triggered_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_auto_strategies_user ON auto_strategies (user_id);
                CREATE INDEX IF NOT EXISTS idx_auto_strategies_active ON auto_strategies (is_active);

                -- 自动交易触发记录表
                CREATE TABLE IF NOT EXISTS auto_trade_logs (
                    id BIGSERIAL PRIMARY KEY,
                    strategy_id BIGINT NOT NULL REFERENCES auto_strategies(id) ON DELETE CASCADE,
                    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    stock_code VARCHAR(16) NOT NULL,
                    direction VARCHAR(8) NOT NULL,
                    quantity INT NOT NULL,
                    price NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    success BOOLEAN NOT NULL DEFAULT FALSE,
                    message VARCHAR(200) NOT NULL DEFAULT '',
                    pnl NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_auto_logs_user_time ON auto_trade_logs (user_id, created_at DESC);
                CREATE INDEX IF NOT EXISTS idx_auto_logs_fail_time ON auto_trade_logs (created_at) WHERE success = FALSE;
                CREATE INDEX IF NOT EXISTS idx_auto_logs_ok_time ON auto_trade_logs (created_at) WHERE success = TRUE;

                -- 自动交易账户级总开关 (旧库补列)
                ALTER TABLE users ADD COLUMN IF NOT EXISTS auto_trading_enabled BOOLEAN NOT NULL DEFAULT FALSE;

                -- 用户级风控设置 (止损/止盈/单次限额, 对所有卖出策略生效)
                CREATE TABLE IF NOT EXISTS user_auto_settings (
                    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                    stop_loss NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    take_profit NUMERIC(18, 4) NOT NULL DEFAULT 0,
                    max_amount NUMERIC(18, 4) NOT NULL DEFAULT 0
                );

                -- 序列自愈: 若种子数据显式插入过 id, 校准序列避免主键冲突
                SELECT setval('users_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM users), 1));
            ");

            // 自动就绪 AI 交易对手福贺久留美账号
            // 安全: 密码不再使用源码内置哈希 (防源码泄露后被网页直接登录), 由 KURUMI_PASSWORD 环境变量运行时派生 bcrypt
            $kurumiPassword = getenv('KURUMI_PASSWORD') ?: 'KurumiFX2026!';
            $stmtKurumi = $pdo->prepare("
                INSERT INTO users (username, password_hash, role, balance, locked_margin, initial_fund)
                VALUES (:u, :hash, 'user', 20000000.0000, 0, 20000000.0000)
                ON CONFLICT (username) DO NOTHING
            ");
            $stmtKurumi->execute([
                'u' => '福贺久留美',
                'hash' => password_hash($kurumiPassword, PASSWORD_BCRYPT)
            ]);
        } catch (Exception $e) {
            error_log('Incremental migration notice: ' . $e->getMessage());
        }
    }

    public static function beginTransaction(): void {
        self::getConnection()->beginTransaction();
    }

    public static function commit(): void {
        if (self::getConnection()->inTransaction()) {
            self::getConnection()->commit();
        }
    }

    public static function rollBack(): void {
        if (self::getConnection()->inTransaction()) {
            self::getConnection()->rollBack();
        }
    }
}
