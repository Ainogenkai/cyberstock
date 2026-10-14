<?php
/**
 * 赛博多人股市 (Cyber Stock Simulator)
 * 全局配置与基础工具
 */

// 严格类型与错误日志
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 设置响应头 (JSON、防嗅探、防点击劫持、跨域)
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// 数据库配置 (优先读取环境变量)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'cyberstock');
define('DB_USER', getenv('DB_USER') ?: 'cyberstock_user');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'CyberStockPass_2026!');
define('APP_SECRET', getenv('APP_SECRET') ?: 'CyberStock_Super_Secret_Key_Change_Me_2026');
define('INITIAL_FUND', (float)(getenv('INITIAL_FUND') ?: 1000000.0));
define('DEFAULT_INVITE_CODE', getenv('DEFAULT_INVITE_CODE') ?: 'CYBER666');

// 交易与市场参数默认值
define('BUY_FEE_RATE', 0.0);             // 免税免佣：买入手续费 0%
define('SELL_FEE_RATE', 0.0);            // 免税免佣：卖出手续费+印花税 0%
define('DAILY_CHECKIN_REWARD', 50000.0); // 每日签到扶持金 50,000 元
define('BANKRUPTCY_THRESHOLD', 10000.0); // 破产救助判定阈值：总资产低于 10,000 元
define('BANKRUPTCY_BAILOUT_AMOUNT', 200000.0); // 破产重组低保资金 200,000 元
define('PRICE_LIMIT_RATE', 0.10);        // 涨跌停限制 ±10%
define('CIRCUIT_THRESHOLD', 0.05);       // 单Tick异动熔断阈值 ±5%
define('SHORT_MARGIN_RATE', 1.00);       // 初始做空保证金比例 100%
define('MAINTENANCE_MARGIN_RATE', 0.30); // 维持保证金比例 30% (净资产/名义市值低于30%触发强平)
define('MARKET_SENSITIVITY', 0.05);      // 订单对价格的冲击敏感度系数

// 自动交易风控参数 (风险控制 tab 展示, 服务端强制执行)
define('AUTO_MAX_STRATEGIES', 5);          // 每人活跃策略数上限
define('AUTO_COOLDOWN_SECONDS', 5);        // 同策略触发冷却 (秒)
define('AUTO_TIME_COOLDOWN_SECONDS', 30);  // 定时条件(time)的触发间隔 (秒)
define('AUTO_DRAWDOWN_HALT', 0.50);        // 总资产跌破初始资金此比例时熔断暂停全部策略
define('AUTO_MAX_LOGS_RETURN', 50);        // 交易记录最多返回条数

/**
 * 成功 JSON 响应
 */
function jsonResponse($data = [], $message = 'ok', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'data' => $data,
        'server_time' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 错误 JSON 响应
 */
function errorResponse($message = '操作失败', $code = 400, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge([
        'code' => $code,
        'message' => $message,
        'data' => null,
        'server_time' => date('c')
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取请求体 JSON
 */
function getJsonInput() {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// mbstring 扩展兜底 (精简/便携 PHP 环境可能未启用 mbstring, 成交昵称脱敏与广播校验依赖它)
if (!function_exists('mb_strlen')) {
    function mb_strlen($s) {
        return (int)preg_match_all('/./us', (string)$s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null) {
        $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_slice($chars, $start, $length));
    }
}

/**
 * 获取客户端认证 Token
 */
function getBearerToken() {
    $headers = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER['Authorization']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
        return $matches[1];
    }
    // 兼容 Cookie 方式
    return $_COOKIE['cyberstock_token'] ?? null;
}

/**
 * 判断当前文件是否为直接请求入口 (而非被其他脚本 require/include 引入)
 * 用于隔离「公共函数库」与「路由分发」：被引入时仅加载函数，绝不执行路由，
 * 否则会因共享 $_GET['action'] 而误触发本文件路由并 errorResponse 退出。
 */
function isApiEntryPoint(string $currentFile): bool {
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($script === '') {
        return false;
    }
    $scriptPath = realpath($script);
    $currentPath = realpath($currentFile);
    return $scriptPath !== false && $currentPath !== false && $scriptPath === $currentPath;
}
