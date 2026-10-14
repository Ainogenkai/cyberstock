<?php
/**
 * 赛博多人股市 - 用户认证与会话管理
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// 仅当本文件为直接请求入口时执行路由；被 trade/portfolio/admin 等引入时只提供认证函数
$isDirectRequest = isApiEntryPoint(__FILE__);

/**
 * 判断当前请求是否走 HTTPS (含反向代理透传场景)
 */
function isHttpsRequest(): bool {
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        return true;
    }
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * 获取当前登录用户
 */
function getCurrentUser(): ?array {
    $token = getBearerToken();
    if (!$token) {
        return null;
    }
    $db = DB::getConnection();
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.role, u.balance, u.locked_margin, u.initial_fund, u.status,
               TO_CHAR(u.created_at, \'YYYY-MM-DD\') AS created_date,
               s.expires_at 
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.token = :token AND s.expires_at > CURRENT_TIMESTAMP
    ');
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();
    if ($user && $user['status'] === 'banned') {
        return null;
    }
    return $user ?: null;
}

/**
 * 必须认证拦截器
 */
function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        errorResponse('未登录或登录状态已失效，请重新登录', 401);
    }
    return $user;
}

/**
 * 必须管理员拦截器
 */
function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        errorResponse('权限不足，仅管理员可执行此操作', 403);
    }
    return $user;
}

// 路由处理 (仅直接请求本文件时执行)
if ($isDirectRequest) {
$action = $_GET['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'register':
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $inviteCode = trim($input['invite_code'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]{2,20}$/u', $username)) {
            errorResponse('用户名需为2-20位中文字符、字母、数字或下划线');
        }
        if (strlen($password) < 6 || strlen($password) > 32) {
            errorResponse('密码长度需在6-32位之间');
        }
        if (empty($inviteCode)) {
            errorResponse('请输入群友内测邀请码');
        }

        $db = DB::getConnection();
        DB::beginTransaction();
        try {
            // 校验邀请码
            $stmtCode = $db->prepare('
                SELECT id, max_uses, used_count, is_active, expires_at 
                FROM invite_codes 
                WHERE code = :code FOR UPDATE
            ');
            $stmtCode->execute(['code' => $inviteCode]);
            $codeRow = $stmtCode->fetch();

            // 如果是系统默认邀请码且数据库中完全不存在，才自动兜底写入初始记录
            if (!$codeRow && $inviteCode === DEFAULT_INVITE_CODE) {
                $stmtIns = $db->prepare('
                    INSERT INTO invite_codes (code, max_uses, used_count, is_active, note) 
                    VALUES (:code, 1000, 0, TRUE, :note) 
                    ON CONFLICT (code) DO NOTHING
                ');
                $stmtIns->execute(['code' => DEFAULT_INVITE_CODE, 'note' => '默认内测邀请码']);
                $stmtCode->execute(['code' => $inviteCode]);
                $codeRow = $stmtCode->fetch();
            }

            // PostgreSQL 布尔字段在 PDO 中返回字符 't' 或 'f'，在 PHP 中直接 !'f' 会错误判定为 false (即误判为生效)！
            // 必须使用 filter_var 严格将 'f'/'t' 解析为真实布尔值：
            $codeActive = $codeRow ? filter_var($codeRow['is_active'], FILTER_VALIDATE_BOOLEAN) : false;

            if (!$codeRow || !$codeActive) {
                DB::rollBack();
                errorResponse('邀请码无效或已被禁用');
            }

            if ($codeRow['used_count'] >= $codeRow['max_uses']) {
                DB::rollBack();
                errorResponse('该邀请码已达到最大使用次数');
            }

            // 检查用户名冲突
            $stmtCheckUser = $db->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(:u)');
            $stmtCheckUser->execute(['u' => $username]);
            if ($stmtCheckUser->fetch()) {
                DB::rollBack();
                errorResponse('该用户名已被注册，请更换');
            }

            // 创建用户
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $initialFund = INITIAL_FUND;
            $stmtUser = $db->prepare('
                INSERT INTO users (username, password_hash, role, balance, locked_margin, initial_fund)
                VALUES (:u, :p, :role, :bal, 0, :init) RETURNING id
            ');
            $stmtUser->execute([
                'u' => $username,
                'p' => $hash,
                'role' => 'user',
                'bal' => $initialFund,
                'init' => $initialFund
            ]);
            $userId = $stmtUser->fetchColumn();

            // 邀请码使用次数 +1
            $stmtInc = $db->prepare('UPDATE invite_codes SET used_count = used_count + 1 WHERE id = :id');
            $stmtInc->execute(['id' => $codeRow['id']]);

            // 初始资金记账
            $stmtLedger = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, description)
                VALUES (:uid, :type, :amt, :bal_after, 0, :ref, :desc)
            ');
            $stmtLedger->execute([
                'uid' => $userId,
                'type' => 'INITIAL_DEPOSIT',
                'amt' => $initialFund,
                'bal_after' => $initialFund,
                'ref' => 'REGISTER',
                'desc' => '新玩家开户初始虚拟资金注入'
            ]);

            // 创建会话 Token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $stmtSess = $db->prepare('
                INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at)
                VALUES (:uid, :tok, :ip, :ua, :exp)
            ');
            $stmtSess->execute([
                'uid' => $userId,
                'tok' => $token,
                'ip' => $ip,
                'ua' => $ua,
                'exp' => $expiresAt
            ]);

            DB::commit();

            setcookie('cyberstock_token', $token, [
                'expires' => time() + 86400 * 30,
                'path' => '/',
                'httponly' => true,
                'secure' => isHttpsRequest(),
                'samesite' => 'Lax'
            ]);

            jsonResponse([
                'token' => $token,
                'user' => [
                    'id' => (int)$userId,
                    'username' => $username,
                    'role' => 'user',
                    'balance' => (float)$initialFund,
                    'locked_margin' => 0.0,
                    'initial_fund' => (float)$initialFund
                ]
            ], '注册成功，欢迎加入赛博股市！');
        } catch (Exception $e) {
            DB::rollBack();
            error_log('Register error: ' . $e->getMessage());
            // 安全脱敏：原始数据库异常仅写入日志，不向前端泄露内部细节
            errorResponse('注册失败，请稍后重试或联系管理员', 500);
        }
        break;

    case 'login':
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($username) || empty($password)) {
            errorResponse('请输入用户名和密码');
        }

        $db = DB::getConnection();

        // 暴力破解防护: 同一用户名+IP 在 10 分钟内失败达 8 次则临时锁定
        $loginIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmtFail = $db->prepare('
            SELECT COUNT(*) FROM login_failures
            WHERE username = :u AND ip_address = :ip AND failed_at >= CURRENT_TIMESTAMP - INTERVAL \'10 minutes\'
        ');
        $stmtFail->execute(['u' => strtolower($username), 'ip' => $loginIp]);
        if ((int)$stmtFail->fetchColumn() >= 8) {
            errorResponse('登录尝试过于频繁，账户已临时锁定 10 分钟，请稍后再试', 429);
        }

        $stmt = $db->prepare('
            SELECT id, username, password_hash, role, balance, locked_margin, initial_fund, status 
            FROM users 
            WHERE LOWER(username) = LOWER(:u)
        ');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // 记录失败尝试并顺带清理 1 天前的过期记录
            try {
                $stmtInsFail = $db->prepare('INSERT INTO login_failures (username, ip_address) VALUES (:u, :ip)');
                $stmtInsFail->execute(['u' => strtolower($username), 'ip' => $loginIp]);
                $db->exec("DELETE FROM login_failures WHERE failed_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
            } catch (Exception $logEx) {
                error_log('Login failure log error: ' . $logEx->getMessage());
            }
            errorResponse('用户名或密码错误');
        }

        if ($user['status'] === 'banned') {
            errorResponse('该账户已被管理员封禁，无法登录');
        }

        // 登录成功: 清空该用户的失败计数
        try {
            $stmtClrFail = $db->prepare('DELETE FROM login_failures WHERE username = :u');
            $stmtClrFail->execute(['u' => strtolower($username)]);
        } catch (Exception $clrEx) {
            error_log('Login failure clear error: ' . $clrEx->getMessage());
        }

        // 生成新 Token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $stmtSess = $db->prepare('
            INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at)
            VALUES (:uid, :tok, :ip, :ua, :exp)
        ');
        $stmtSess->execute([
            'uid' => $user['id'],
            'tok' => $token,
            'ip' => $ip,
            'ua' => $ua,
            'exp' => $expiresAt
        ]);

        setcookie('cyberstock_token', $token, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'httponly' => true,
            'secure' => isHttpsRequest(),
            'samesite' => 'Lax'
        ]);

        jsonResponse([
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'balance' => (float)$user['balance'],
                'locked_margin' => (float)$user['locked_margin'],
                'initial_fund' => (float)$user['initial_fund']
            ]
        ], '登录成功');
        break;

    case 'logout':
        $token = getBearerToken();
        if ($token) {
            $db = DB::getConnection();
            $stmt = $db->prepare('DELETE FROM user_sessions WHERE token = :tok');
            $stmt->execute(['tok' => $token]);
        }
        setcookie('cyberstock_token', '', ['expires' => time() - 3600, 'path' => '/']);
        jsonResponse(null, '已安全退出登录');
        break;

    case 'me':
        $user = requireAuth();
        jsonResponse([
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'balance' => (float)$user['balance'],
                'locked_margin' => (float)$user['locked_margin'],
                'initial_fund' => (float)$user['initial_fund'],
                'created_date' => $user['created_date'] ?? date('Y-m-d')
            ]
        ]);
        break;

    case 'delete_account':
        $user = requireAuth();
        $password = trim($input['password'] ?? '');

        if (empty($password)) {
            errorResponse('请输入密码以确认注销账户');
        }

        $db = DB::getConnection();

        // 校验密码
        $stmtP = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmtP->execute(['id' => $user['id']]);
        $hash = $stmtP->fetchColumn();

        if (!password_verify($password, $hash)) {
            errorResponse('密码不正确，无法注销账户');
        }

        // 检查是否尚有持仓
        $stmtPos = $db->prepare('SELECT COUNT(*) FROM positions WHERE user_id = :uid AND quantity > 0');
        $stmtPos->execute(['uid' => $user['id']]);
        if ((int)$stmtPos->fetchColumn() > 0) {
            errorResponse('账户中尚有多头持仓或空单，请先在持仓/交易页清仓平仓后再注销账户');
        }

        if ($user['role'] === 'admin') {
            errorResponse('管理员账号禁止注销');
        }

        DB::beginTransaction();
        try {
            $stmtDel = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmtDel->execute(['id' => $user['id']]);
            DB::commit();

            setcookie('cyberstock_token', '', ['expires' => time() - 3600, 'path' => '/']);
            jsonResponse(null, '账户已安全注销，所有数据已清除');
        } catch (Exception $e) {
            DB::rollBack();
            error_log('Delete account error: ' . $e->getMessage());
            errorResponse('注销账户失败，请稍后重试', 500);
        }
        break;

    case 'change_password':
        $user = requireAuth();
        $oldPass = trim($input['old_password'] ?? '');
        $newPass = trim($input['new_password'] ?? '');

        if (strlen($newPass) < 6 || strlen($newPass) > 32) {
            errorResponse('新密码长度需在6-32位之间');
        }

        $db = DB::getConnection();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        $currHash = $stmt->fetchColumn();

        if (!password_verify($oldPass, $currHash)) {
            errorResponse('当前密码不正确');
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $stmtUp = $db->prepare('UPDATE users SET password_hash = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmtUp->execute(['p' => $newHash, 'id' => $user['id']]);

        // 安全加固：使该账号所有其他会话失效 (保留当前会话，避免改密后自己被登出)
        $curToken = getBearerToken();
        if ($curToken) {
            $stmtDelSess = $db->prepare('DELETE FROM user_sessions WHERE user_id = :id AND token <> :cur');
            $stmtDelSess->execute(['id' => $user['id'], 'cur' => $curToken]);
        } else {
            $stmtDelSess = $db->prepare('DELETE FROM user_sessions WHERE user_id = :id');
            $stmtDelSess->execute(['id' => $user['id']]);
        }

        jsonResponse(null, '密码修改成功，其他设备已自动退出登录');
        break;

    default:
        errorResponse('未知的认证操作: ' . htmlspecialchars($action), 404);
        break;
}
}
