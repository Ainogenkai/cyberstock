-- ==============================================================================
-- 赛博多人股市 (Cyber Stock Simulator) - PostgreSQL 数据库初始化脚本
-- 版本: v1.0.0
-- 字符集: UTF-8
-- 时区约定: 所有 TIMESTAMP 均使用 UTC (TIMESTAMP WITH TIME ZONE)
-- ==============================================================================

-- 扩展支持
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 1. 用户表 (users)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    balance NUMERIC(18, 4) NOT NULL DEFAULT 1000000.0000 CHECK (balance >= 0),
    locked_margin NUMERIC(18, 4) NOT NULL DEFAULT 0.0000 CHECK (locked_margin >= 0),
    initial_fund NUMERIC(18, 4) NOT NULL DEFAULT 1000000.0000,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'banned')),
    auto_trading_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_users_role ON users (role);

-- 2. 邀请码表 (invite_codes)
CREATE TABLE IF NOT EXISTS invite_codes (
    id SERIAL PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    created_by INT REFERENCES users(id) ON DELETE SET NULL,
    max_uses INT NOT NULL DEFAULT 1,
    used_count INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    note VARCHAR(255),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_invite_codes_code ON invite_codes (code);

-- 3. 用户会话表 (user_sessions)
CREATE TABLE IF NOT EXISTS user_sessions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(128) NOT NULL UNIQUE,
    ip_address VARCHAR(64),
    user_agent TEXT,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON user_sessions (token);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires_at ON user_sessions (expires_at);

-- 4. 股票基础与实时行情表 (stocks)
CREATE TABLE IF NOT EXISTS stocks (
    code VARCHAR(16) PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    industry VARCHAR(64) NOT NULL,
    current_price NUMERIC(12, 4) NOT NULL CHECK (current_price > 0),
    prev_close NUMERIC(12, 4) NOT NULL CHECK (prev_close > 0),
    open_price NUMERIC(12, 4) NOT NULL CHECK (open_price > 0),
    high_price NUMERIC(12, 4) NOT NULL CHECK (high_price > 0),
    low_price NUMERIC(12, 4) NOT NULL CHECK (low_price > 0),
    volume BIGINT NOT NULL DEFAULT 0,
    amount NUMERIC(18, 4) NOT NULL DEFAULT 0,
    liquidity_depth BIGINT NOT NULL DEFAULT 50000, -- 市场深度基准股数
    total_shares BIGINT NOT NULL DEFAULT 200000 CHECK (total_shares > 0),       -- 全服总流通盘 (严格锁仓物理上限)
    max_short_shares BIGINT NOT NULL DEFAULT 60000 CHECK (max_short_shares >= 0), -- 融券池借券配额 (逼空券池上限)
    unlock_required_users INT NOT NULL DEFAULT 1 CHECK (unlock_required_users >= 1), -- 解锁所需全服玩家数
    status VARCHAR(20) NOT NULL DEFAULT 'TRADING' CHECK (status IN ('TRADING', 'CIRCUIT_BREAKER', 'HALTED')),
    circuit_breaker_until TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stocks_industry ON stocks (industry);

-- 5. 市场全局状态表 (market_state，单行表)
CREATE TABLE IF NOT EXISTS market_state (
    id INT PRIMARY KEY DEFAULT 1,
    current_day INT NOT NULL DEFAULT 1,
    tick_count BIGINT NOT NULL DEFAULT 0,
    is_paused BOOLEAN NOT NULL DEFAULT FALSE,
    trading_phase VARCHAR(30) NOT NULL DEFAULT 'OPEN' CHECK (trading_phase IN ('OPEN', 'CLOSED', 'AUCTION')),
    last_tick_time TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    settings JSONB NOT NULL DEFAULT '{"tick_interval_sec": 3, "buy_fee_rate": 0.0003, "sell_fee_rate": 0.0013, "margin_rate": 1.0, "maintenance_margin_rate": 0.3, "price_limit_rate": 0.10, "circuit_threshold": 0.05}'::jsonb
);

-- 6. 订单表 (orders)
CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    stock_code VARCHAR(16) NOT NULL REFERENCES stocks(code),
    direction VARCHAR(20) NOT NULL CHECK (direction IN ('BUY', 'SELL', 'SHORT_OPEN', 'SHORT_CLOSE')),
    order_type VARCHAR(20) NOT NULL DEFAULT 'MARKET' CHECK (order_type IN ('MARKET', 'LIMIT')),
    price NUMERIC(12, 4) NOT NULL CHECK (price > 0),
    quantity BIGINT NOT NULL CHECK (quantity > 0),
    filled_quantity BIGINT NOT NULL DEFAULT 0 CHECK (filled_quantity >= 0),
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING' CHECK (status IN ('PENDING', 'FILLED', 'PARTIALLY_FILLED', 'CANCELLED', 'REJECTED')),
    client_req_id VARCHAR(64), -- 幂等性校验ID
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders (user_id);
CREATE INDEX IF NOT EXISTS idx_orders_stock_code ON orders (stock_code);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders (status);
CREATE INDEX IF NOT EXISTS idx_orders_client_req_id ON orders (client_req_id);

-- 7. 成交明细表 (trades)
CREATE TABLE IF NOT EXISTS trades (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    stock_code VARCHAR(16) NOT NULL REFERENCES stocks(code),
    direction VARCHAR(20) NOT NULL CHECK (direction IN ('BUY', 'SELL', 'SHORT_OPEN', 'SHORT_CLOSE', 'LIQUIDATION')),
    price NUMERIC(12, 4) NOT NULL CHECK (price > 0),
    quantity BIGINT NOT NULL CHECK (quantity > 0),
    amount NUMERIC(18, 4) NOT NULL CHECK (amount >= 0),
    fee NUMERIC(18, 4) NOT NULL DEFAULT 0 CHECK (fee >= 0),
    pnl NUMERIC(18, 4) NOT NULL DEFAULT 0, -- 实现盈亏
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_trades_user_id ON trades (user_id);
CREATE INDEX IF NOT EXISTS idx_trades_stock_code ON trades (stock_code);
CREATE INDEX IF NOT EXISTS idx_trades_created_at ON trades (created_at DESC);

-- 8. 玩家持仓表 (positions)
CREATE TABLE IF NOT EXISTS positions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    stock_code VARCHAR(16) NOT NULL REFERENCES stocks(code),
    position_type VARCHAR(10) NOT NULL CHECK (position_type IN ('LONG', 'SHORT')),
    quantity BIGINT NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    available_quantity BIGINT NOT NULL DEFAULT 0 CHECK (available_quantity >= 0), -- T+1 或未锁定持仓
    avg_cost NUMERIC(12, 4) NOT NULL DEFAULT 0 CHECK (avg_cost >= 0),
    locked_margin NUMERIC(18, 4) NOT NULL DEFAULT 0 CHECK (locked_margin >= 0), -- 空头锁定的保证金
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_stock_position UNIQUE (user_id, stock_code, position_type)
);

CREATE INDEX IF NOT EXISTS idx_positions_user_id ON positions (user_id);
CREATE INDEX IF NOT EXISTS idx_positions_stock_code ON positions (stock_code);

-- 9. 虚拟资金账目变动流水 (account_ledger)
CREATE TABLE IF NOT EXISTS account_ledger (
    id BIGSERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    change_type VARCHAR(32) NOT NULL,
    amount NUMERIC(18, 4) NOT NULL, -- 正数为增加，负数为扣减
    balance_after NUMERIC(18, 4) NOT NULL CHECK (balance_after >= 0),
    locked_margin_after NUMERIC(18, 4) NOT NULL CHECK (locked_margin_after >= 0),
    ref_type VARCHAR(32), -- 'TRADE', 'ORDER', 'ADMIN', 'LIQUIDATION'
    ref_id VARCHAR(64),
    description TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_account_ledger_user_id ON account_ledger (user_id);
CREATE INDEX IF NOT EXISTS idx_account_ledger_created_at ON account_ledger (created_at DESC);

-- 10. K线数据表 (candles)
CREATE TABLE IF NOT EXISTS candles (
    id BIGSERIAL PRIMARY KEY,
    stock_code VARCHAR(16) NOT NULL REFERENCES stocks(code),
    period VARCHAR(10) NOT NULL DEFAULT '1m' CHECK (period IN ('1m', '5m', '1d')),
    candle_time TIMESTAMPTZ NOT NULL,
    open NUMERIC(12, 4) NOT NULL,
    high NUMERIC(12, 4) NOT NULL,
    low NUMERIC(12, 4) NOT NULL,
    close NUMERIC(12, 4) NOT NULL,
    volume BIGINT NOT NULL DEFAULT 0,
    amount NUMERIC(18, 4) NOT NULL DEFAULT 0,
    CONSTRAINT uq_candle_stock_period_time UNIQUE (stock_code, period, candle_time)
);

CREATE INDEX IF NOT EXISTS idx_candles_lookup ON candles (stock_code, period, candle_time DESC);

-- 11. 市场事件表 (market_events)
CREATE TABLE IF NOT EXISTS market_events (
    id BIGSERIAL PRIMARY KEY,
    title VARCHAR(128) NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(32) NOT NULL CHECK (category IN ('MARKET_SURGE', 'SHORT_SQUEEZE', 'VOLATILITY_HALT', 'POLICY_NEWS', 'TECH_BREAKTHROUGH', 'INDUSTRY_RUMOR', 'RANDOM_MACRO')),
    source_type VARCHAR(32) NOT NULL CHECK (source_type IN ('PLAYER_ACTION', 'SYSTEM_SIM', 'REAL_MAPPING', 'ADMIN', 'BOT_BROADCAST')),
    stock_code VARCHAR(16) REFERENCES stocks(code),
    industry VARCHAR(64),
    impact_factor NUMERIC(8, 4) NOT NULL DEFAULT 0, -- 价格漂移影响因子 (-0.10 ~ +0.10)
    duration_sec INT NOT NULL DEFAULT 300, -- 持续秒数
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_executed BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_market_events_active ON market_events (is_active, expires_at);
CREATE INDEX IF NOT EXISTS idx_market_events_stock ON market_events (stock_code);

-- 13. 每日签到记录表 (daily_checkins)
CREATE TABLE IF NOT EXISTS daily_checkins (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    checkin_date DATE NOT NULL,
    reward_amount NUMERIC(18, 4) NOT NULL DEFAULT 50000.0000,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_checkin_date UNIQUE (user_id, checkin_date)
);

CREATE INDEX IF NOT EXISTS idx_daily_checkins_user_date ON daily_checkins (user_id, checkin_date);

-- 14. 破产救助低保记录表 (bankruptcy_bailouts)
CREATE TABLE IF NOT EXISTS bankruptcy_bailouts (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bailout_amount NUMERIC(18, 4) NOT NULL DEFAULT 200000.0000,
    total_assets_before NUMERIC(18, 4) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bankruptcy_bailouts_user ON bankruptcy_bailouts (user_id, created_at DESC);

-- 15. 跑马灯广播限频记录表 (bot_broadcasts) - MCP 操盘手等登录用户全服广播
CREATE TABLE IF NOT EXISTS bot_broadcasts (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title VARCHAR(200) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bot_broadcasts_user_time ON bot_broadcasts (user_id, created_at DESC);

-- 16. 登录暴力破解防护表 (login_failures)
CREATE TABLE IF NOT EXISTS login_failures (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    ip_address VARCHAR(64) NOT NULL DEFAULT '',
    failed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_failures_lookup ON login_failures (username, ip_address, failed_at DESC);

-- 17. 自动交易策略表 (auto_strategies) - 服务器端引擎, 关网页不丢失
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

-- 18. 自动交易触发记录表 (auto_trade_logs)
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

-- 12. 管理审计日志表 (admin_logs)
CREATE TABLE IF NOT EXISTS admin_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(32),
    target_id VARCHAR(64),
    details_json JSONB,
    ip_address VARCHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_logs_admin_id ON admin_logs (admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_logs_created_at ON admin_logs (created_at DESC);

-- ==============================================================================
-- 初始数据填充 (Seeds)
-- ==============================================================================

-- 初始化市场单行配置
INSERT INTO market_state (id, current_day, tick_count, is_paused, trading_phase)
VALUES (1, 1, 0, FALSE, 'OPEN')
ON CONFLICT (id) DO NOTHING;

-- 初始内置管理员账户 (用户名: admin, 默认密码: CyberStock@2026, 部署后可通过控制台或命令行修改)
-- 密码哈希采用安全 bcrypt ($2y$10$...)
INSERT INTO users (id, username, password_hash, role, balance, locked_margin, initial_fund)
VALUES (1, 'admin', '$2y$10$iR/6MHC4p.XSGLGYL4YGfuYQlJBTcyM1KC7.ZPmPCyEuKajT8DACe', 'admin', 10000000.0000, 0, 10000000.0000)
ON CONFLICT (username) DO NOTHING;

-- 关键: 显式插入 id=1 后必须校准序列, 否则后续用户注册会因序列落后于主键而冲突
SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));

-- 初始默认邀请码 (默认生成通用内测邀请码: CYBER666, CYBER888, VIP999)
INSERT INTO invite_codes (code, created_by, max_uses, used_count, is_active, note)
VALUES 
    ('CYBER666', 1, 100, 0, TRUE, '群友内测默认邀请码 1'),
    ('CYBER888', 1, 100, 0, TRUE, '群友内测默认邀请码 2'),
    ('VIP999', 1, 50, 0, TRUE, '特邀群友邀请码')
ON CONFLICT (code) DO NOTHING;

-- 初始股票池种子数据 (精选A股代表性龙头与原项目彩蛋股票「影视飓风」)
INSERT INTO stocks (code, name, industry, current_price, prev_close, open_price, high_price, low_price, liquidity_depth)
VALUES
    ('000001', '平安银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600036', '招商银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601398', '工商银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601288', '农业银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601988', '中国银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601328', '交通银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600016', '民生银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600000', '浦发银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601166', '兴业银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601818', '光大银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('002142', '宁波银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('601009', '南京银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600926', '杭州银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('002807', '江阴银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600908', '无锡银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('002839', '张家港行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('603323', '苏农银行', '银行', 12.5000, 12.5000, 12.5000, 12.5000, 12.5000, 150000),
    ('600030', '中信证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600837', '海通证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601688', '华泰证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600999', '招商证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000776', '广发证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601211', '国泰君安', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601236', '红塔证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601696', '中银证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601878', '浙商证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002797', '第一创业', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002939', '长城证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002926', '华西证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601990', '南京证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601066', '中信建投', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600109', '国金证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601555', '东吴证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600369', '西南证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000728', '国元证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000750', '国海证券', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601099', '太平洋', '券商', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601318', '中国平安', '保险', 55.0000, 55.0000, 55.0000, 55.0000, 55.0000, 50000),
    ('601628', '中国人寿', '保险', 55.0000, 55.0000, 55.0000, 55.0000, 55.0000, 50000),
    ('601336', '新华保险', '保险', 55.0000, 55.0000, 55.0000, 55.0000, 55.0000, 50000),
    ('601601', '中国太保', '保险', 55.0000, 55.0000, 55.0000, 55.0000, 55.0000, 50000),
    ('600291', '西水股份', '保险', 55.0000, 55.0000, 55.0000, 55.0000, 55.0000, 50000),
    ('600519', '贵州茅台', '白酒', 1680.0000, 1680.0000, 1680.0000, 1680.0000, 1680.0000, 15000),
    ('000858', '五粮液', '白酒', 168.0000, 168.0000, 168.0000, 168.0000, 168.0000, 50000),
    ('000568', '泸州老窖', '白酒', 168.0000, 168.0000, 168.0000, 168.0000, 168.0000, 50000),
    ('600809', '山西汾酒', '白酒', 168.0000, 168.0000, 168.0000, 168.0000, 168.0000, 50000),
    ('002304', '洋河股份', '白酒', 168.0000, 168.0000, 168.0000, 168.0000, 168.0000, 50000),
    ('000596', '古井贡酒', '白酒', 168.0000, 168.0000, 168.0000, 168.0000, 168.0000, 50000),
    ('600887', '伊利股份', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600597', '光明乳业', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000895', '双汇发展', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603288', '海天味业', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600872', '中炬高新', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002507', '涪陵榨菜', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603027', '千禾味业', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600305', '恒顺醋业', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002847', '盐津铺子', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603517', '绝味食品', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603345', '安井食品', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300999', '金龙鱼', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002557', '洽洽食品', '食品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000848', '承德露露', '饮料', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600276', '恒瑞医药', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('000538', '云南白药', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('600436', '片仔癀', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('000963', '华东医药', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('600196', '复星医药', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('002001', '新和成', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('300003', '乐普医疗', '医疗', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600587', '新华医疗', '医疗', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603259', '药明康德', '医药服务', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('300760', '迈瑞医疗', '医疗器械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688185', '康希诺', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600079', '人福医药', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('002422', '科伦药业', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('000623', '吉林敖东', '医药', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('300142', '沃森生物', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300122', '智飞生物', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603392', '万泰生物', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300601', '康泰生物', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000661', '长春高新', '生物制药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300347', '泰格医药', '医药服务', 48.0000, 48.0000, 48.0000, 48.0000, 48.0000, 50000),
    ('000725', '京东方A', '面板', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000100', 'TCL科技', '面板', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002415', '海康威视', '安防', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002236', '大华股份', '安防', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603501', '韦尔股份', '半导体', 92.0000, 92.0000, 92.0000, 92.0000, 92.0000, 50000),
    ('688981', '中芯国际', '半导体', 92.0000, 92.0000, 92.0000, 92.0000, 92.0000, 50000),
    ('002371', '北方华创', '半导体设备', 92.0000, 92.0000, 92.0000, 92.0000, 92.0000, 50000),
    ('688012', '中微公司', '半导体设备', 92.0000, 92.0000, 92.0000, 92.0000, 92.0000, 50000),
    ('603986', '兆易创新', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688008', '澜起科技', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002049', '紫光国微', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300782', '卓胜微', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603160', '汇顶科技', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688396', '华润微', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688126', '沪硅产业', '半导体材料', 92.0000, 92.0000, 92.0000, 92.0000, 92.0000, 50000),
    ('300661', '圣邦股份', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688018', '乐鑫科技', '芯片', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300408', '三环集团', '电子', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002241', '歌尔股份', '电子', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601138', '工业富联', '电子制造', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002475', '立讯精密', '电子', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300433', '蓝思科技', '电子', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002273', '水晶光电', '光学', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002456', '欧菲光', '光学', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603933', '睿能科技', '电子', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300750', '宁德时代', '新能源', 250.0000, 250.0000, 250.0000, 250.0000, 250.0000, 50000),
    ('002594', '比亚迪', '新能源汽车', 290.0000, 290.0000, 290.0000, 290.0000, 290.0000, 50000),
    ('002812', '恩捷股份', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('300014', '亿纬锂能', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('002460', '赣锋锂业', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('002709', '天赐材料', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('002466', '天齐锂业', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('300073', '当升科技', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('300207', '欣旺达', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('002074', '国轩高科', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('603659', '璞泰来', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('002340', '格林美', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('300568', '星源材质', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('603026', '石大胜华', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('601012', '隆基绿能', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600438', '通威股份', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688599', '天合光能', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300274', '阳光电源', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002459', '晶澳科技', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600732', '爱旭股份', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603806', '福斯特', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601865', '福莱特', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002056', '横店东磁', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600481', '双良节能', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688223', '晶科能源', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688680', '海优新材', '光伏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603019', '中科曙光', '计算机', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600570', '恒生电子', '金融科技', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300033', '同花顺', '金融科技', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601360', '三六零', '网络安全', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688111', '金山办公', '软件', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300413', '芒果超媒', '传媒', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002027', '分众传媒', '传媒', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300059', '东方财富', '金融科技', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002230', '科大讯飞', '人工智能', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300496', '中科创达', '软件', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300308', '中际旭创', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300502', '新易盛', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000938', '紫光股份', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600498', '烽火通信', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000063', '中兴通讯', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000333', '美的集团', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000651', '格力电器', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600690', '海尔智家', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002032', '苏泊尔', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002242', '九阳股份', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603486', '科沃斯', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688169', '石头科技', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002508', '老板电器', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002035', '华帝股份', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000921', '海信家电', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600060', '海信视像', '家电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603515', '欧普照明', '照明', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603195', '公牛集团', '电器', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601633', '长城汽车', '汽车', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('601238', '广汽集团', '汽车', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('600104', '上汽集团', '汽车', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('000625', '长安汽车', '汽车', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('600660', '福耀玻璃', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('601799', '星宇股份', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('603596', '伯特利', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('002048', '宁波华翔', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('002126', '银轮股份', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('600741', '华域汽车', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('601127', '赛力斯', '汽车', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('000338', '潍柴动力', '汽车零部件', 120.0000, 120.0000, 120.0000, 120.0000, 120.0000, 50000),
    ('000002', '万科A', '房地产', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600048', '保利发展', '房地产', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('001979', '招商蛇口', '房地产', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600606', '绿地控股', '房地产', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000069', '华侨城A', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601668', '中国建筑', '基建', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601390', '中国中铁', '基建', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601186', '中国铁建', '基建', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601669', '中国电建', '基建', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601800', '中国交建', '基建', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600585', '海螺水泥', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600881', '亚泰集团', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601636', '旗滨集团', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002271', '东方雨虹', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000786', '北新建材', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002372', '伟星新材', '建材', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600309', '万华化学', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002064', '华峰化学', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601233', '桐昆股份', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600346', '恒力石化', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002493', '荣盛石化', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600486', '扬农化工', '农药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000830', '鲁西化工', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002601', '龙佰集团', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600409', '三友化工', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002092', '中泰化学', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600143', '金发科技', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300285', '国瓷材料', '化工', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601899', '紫金矿业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603993', '洛阳钼业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600362', '江西铜业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600111', '北方稀土', '稀土', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600010', '包钢股份', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600019', '宝钢股份', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000708', '中信特钢', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600507', '方大特钢', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002110', '三钢闽光', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000932', '华菱钢铁', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600808', '马钢股份', '钢铁', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000878', '云南铜业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000630', '铜陵有色', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601600', '中国铝业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603799', '华友钴业', '有色', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601088', '中国神华', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601225', '陕西煤业', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600028', '中国石化', '石油', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601857', '中国石油', '石油', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600900', '长江电力', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('601985', '中国核电', '核电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('003816', '中国广核', '核电', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600011', '华能国际', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('600886', '国投电力', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('600795', '国电电力', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('600863', '内蒙华电', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('000591', '太阳能', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('601016', '节能风电', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('601222', '林洋能源', '新能源', 150.0000, 150.0000, 150.0000, 150.0000, 150.0000, 50000),
    ('600021', '上海电力', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('600023', '浙能电力', '电力', 28.0000, 28.0000, 28.0000, 28.0000, 28.0000, 50000),
    ('601015', '陕西黑猫', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600348', '阳泉煤业', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601699', '潞安环能', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600508', '上海能源', '煤炭', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601006', '大秦铁路', '铁路', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600009', '上海机场', '机场', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600029', '南方航空', '航空', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601111', '中国国航', '航空', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600115', '东方航空', '航空', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601919', '中远海控', '航运', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601866', '中远海发', '航运', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600018', '上港集团', '港口', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('001872', '招商港口', '港口', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601816', '京沪高铁', '铁路', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600350', '山东高速', '高速', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600377', '宁沪高速', '高速', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000429', '粤高速A', '高速', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600012', '皖通高速', '高速', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601188', '龙江交通', '高速', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002352', '顺丰控股', '物流', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600233', '圆通速递', '物流', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002120', '韵达股份', '物流', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002468', '申通快递', '物流', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603056', '德邦股份', '物流', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600050', '中国联通', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600941', '中国移动', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601728', '中国电信', '通信', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600893', '航发动力', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600760', '中航沈飞', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('000768', '中航西飞', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600372', '中航电子', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('002179', '中航光电', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600118', '中国卫星', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600967', '内蒙一机', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600391', '航发科技', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('000519', '中兵红箭', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('002414', '高德红外', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600862', '中航高科', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('300034', '钢研高纳', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600184', '光电股份', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('600435', '北方导航', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('000547', '航天发展', '军工', 42.0000, 42.0000, 42.0000, 42.0000, 42.0000, 50000),
    ('601100', '恒立液压', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000425', '徐工机械', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000157', '中联重科', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600031', '三一重工', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300124', '汇川技术', '工业自动化', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002008', '大族激光', '激光设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300450', '先导智能', '设备制造', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603638', '艾迪精密', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601766', '中国中车', '轨道交通', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688017', '绿的谐波', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688558', '国盛智科', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300083', '创世纪', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002611', '东方精工', '机械', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300161', '华中数控', '数控', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002747', '埃斯顿', '机器人', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688165', '埃夫特', '机器人', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603666', '亿嘉和', '机器人', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300024', '机器人', '机器人', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688022', '瀚川智能', '智能装备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688006', '杭可科技', '设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('688516', '奥特维', '设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300316', '晶盛机电', '光伏设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300724', '捷佳伟创', '光伏设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300751', '迈为股份', '光伏设备', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600703', '三安光电', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300296', '利亚德', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002449', '国星光电', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300232', '洲明科技', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002745', '木林森', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300389', '艾比森', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002587', '奥拓电子', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300303', '聚飞光电', 'LED', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600754', '锦江酒店', '酒店', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600258', '首旅酒店', '酒店', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300144', '宋城演艺', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601888', '中国中免', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000888', '峨眉山A', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000978', '桂林旅游', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600138', '中青旅', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600749', '西藏旅游', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002707', '众信旅游', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000524', '岭南控股', '酒店', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600593', '大连圣亚', '旅游', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002607', '中公教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300010', '豆神教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300359', '全通教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002621', '美吉姆', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600730', '中国高科', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600661', '昂立教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000526', '学大教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603377', '东方时尚', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002659', '凯文教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300192', '科德教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300338', '开元教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300654', '世纪天鸿', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('003032', '传智教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('605098', '行动教育', '教育', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600398', '海澜之家', '服装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002563', '森马服饰', '服装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603877', '太平鸟', '服装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600177', '雅戈尔', '服装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002154', '报喜鸟', '服装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002293', '罗莱生活', '家纺', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603365', '水星家纺', '家纺', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002327', '富安娜', '家纺', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601339', '百隆东方', '纺织', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600987', '航民股份', '纺织', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002714', '牧原股份', '养殖', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300498', '温氏股份', '养殖', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002157', '正邦科技', '养殖', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002124', '天邦股份', '养殖', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000876', '新希望', '养殖', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002385', '大北农', '农业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600598', '北大荒', '农业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002041', '登海种业', '种业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000998', '隆平高科', '种业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600354', '敦煌种业', '种业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300087', '荃银高科', '种业', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002200', '绿康生化', '农药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600596', '新安股份', '农药', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002078', '太阳纸业', '造纸', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000488', '晨鸣纸业', '造纸', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600567', '山鹰国际', '造纸', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600966', '博汇纸业', '造纸', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002191', '劲嘉股份', '包装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002228', '合兴包装', '包装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002831', '裕同科技', '包装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601968', '宝钢包装', '包装', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002024', '苏宁易购', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('601933', '永辉超市', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603708', '家家悦', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002251', '步步高', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002697', '红旗连锁', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600729', '重庆百货', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600859', '王府井', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('000759', '中百集团', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('600827', '百联股份', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002419', '天虹股份', '零售', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300888', '稳健医疗', '医疗用品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002511', '中顺洁柔', '日用品', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603899', '晨光股份', '文具', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002301', '齐心集团', '文具', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300251', '光线传媒', '传媒', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300133', '华策影视', '传媒', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('999999', '影视飓风', '传媒', 66.6600, 66.6600, 66.6600, 66.6600, 66.6600, 50000),
    ('002624', '完美世界', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002555', '三七互娱', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300418', '昆仑万维', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('603444', '吉比特', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300031', '宝通科技', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('002602', '世纪华通', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300459', '汤姆猫', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000),
    ('300052', '中青宝', '游戏', 25.0000, 25.0000, 25.0000, 25.0000, 25.0000, 50000)
ON CONFLICT (code) DO NOTHING;

-- 初始化第一批历史 K 线数据（每只股票生成一根初始开盘日 K 线）
INSERT INTO candles (stock_code, period, candle_time, open, high, low, close, volume, amount)
SELECT 
    code,
    '1m',
    CURRENT_TIMESTAMP - INTERVAL '1 minute',
    current_price,
    current_price,
    current_price,
    current_price,
    1000,
    current_price * 1000
FROM stocks
ON CONFLICT DO NOTHING;
