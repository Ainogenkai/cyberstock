<?php
/**
 * 赛博多人股市 - 管理员控制台与群主管理接口
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$admin = requireAdmin();
$db = DB::getConnection();
$action = $_GET['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'invite_codes_list':
        $stmt = $db->query('
            SELECT id, code, max_uses, used_count, is_active, note,
                   TO_CHAR(created_at, \'YYYY-MM-DD HH24:MI:SS\') AS created_time,
                   TO_CHAR(expires_at, \'YYYY-MM-DD HH24:MI:SS\') AS expires_time
            FROM invite_codes
            ORDER BY created_at DESC
        ');
        // PostgreSQL 布尔字段经 PDO 返回 't'/'f' 字符串，直接输出会导致前端 truthy 误判 (永远显示"有效")
        $codes = array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'code' => $c['code'],
                'max_uses' => (int)$c['max_uses'],
                'used_count' => (int)$c['used_count'],
                'is_active' => filter_var($c['is_active'], FILTER_VALIDATE_BOOLEAN),
                'note' => $c['note'],
                'created_time' => $c['created_time'],
                'expires_time' => $c['expires_time']
            ];
        }, $stmt->fetchAll());
        jsonResponse(['codes' => $codes]);
        break;

    case 'invite_code_create':
        $code = trim($input['code'] ?? '');
        $maxUses = max(1, (int)($input['max_uses'] ?? 10));
        $note = trim($input['note'] ?? '社群专属邀请码');

        if (empty($code)) {
            // 自动生成 8 位随机大写邀请码
            $code = 'VIP' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        }

        $stmtIns = $db->prepare('
            INSERT INTO invite_codes (code, created_by, max_uses, used_count, is_active, note)
            VALUES (:c, :aid, :mu, 0, TRUE, :n)
        ');
        try {
            $stmtIns->execute(['c' => $code, 'aid' => $admin['id'], 'mu' => $maxUses, 'n' => $note]);
        } catch (Exception $e) {
            errorResponse('邀请码已存在或格式冲突');
        }

        // 审计
        $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'CREATE_INVITE\', \'INVITE\', :t, :d)');
        $stmtLog->execute(['a' => $admin['id'], 't' => $code, 'd' => json_encode(['max_uses' => $maxUses, 'note' => $note])]);

        jsonResponse(['code' => $code, 'max_uses' => $maxUses], '邀请码创建成功');
        break;

    case 'invite_code_toggle':
        $codeId = (int)($input['id'] ?? 0);
        $isActive = filter_var($input['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // PostgreSQL 的 is_active 是 boolean 类型，显式转换避免整型注入或类型失真
        $stmtUp = $db->prepare('UPDATE invite_codes SET is_active = :act::boolean WHERE id = :id');
        $stmtUp->execute(['act' => $isActive ? 'true' : 'false', 'id' => $codeId]);

        // 审计
        $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'TOGGLE_INVITE\', \'INVITE\', :t, :d)');
        $stmtLog->execute(['a' => $admin['id'], 't' => (string)$codeId, 'd' => json_encode(['is_active' => $isActive])]);

        jsonResponse(null, '邀请码状态已更新');
        break;

    case 'users_list':
        $stmt = $db->query('
            SELECT u.id, u.username, u.role, u.balance, u.locked_margin, u.initial_fund, u.status,
                   TO_CHAR(u.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS created_time,
                   COALESCE((SELECT COUNT(*) FROM trades WHERE user_id = u.id), 0) AS trade_count
            FROM users u
            ORDER BY u.id ASC
        ');
        jsonResponse(['users' => $stmt->fetchAll()]);
        break;

    case 'user_adjust_fund':
        $userId = (int)($input['user_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0.0);
        $reason = trim($input['reason'] ?? '管理员资金调整');

        if ($userId <= 0 || $amount == 0.0) {
            errorResponse('请提供合法的用户ID和非零调整金额');
        }

        DB::beginTransaction();
        try {
            $stmtU = $db->prepare('SELECT balance, locked_margin, username FROM users WHERE id = :id FOR UPDATE');
            $stmtU->execute(['id' => $userId]);
            $targetUser = $stmtU->fetch();

            if (!$targetUser) {
                DB::rollBack();
                errorResponse('目标用户不存在');
            }

            $curBal = (float)$targetUser['balance'];
            $newBal = round($curBal + $amount, 4);

            if ($newBal < 0) {
                DB::rollBack();
                errorResponse('扣减金额超过该用户现有余额');
            }

            $stmtUp = $db->prepare('UPDATE users SET balance = :b, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmtUp->execute(['b' => $newBal, 'id' => $userId]);

            // 记账
            $stmtLedger = $db->prepare('
                INSERT INTO account_ledger (user_id, change_type, amount, balance_after, locked_margin_after, ref_type, description)
                VALUES (:uid, \'ADMIN_ADJUST\', :amt, :bal, :lm, \'ADMIN\', :desc)
            ');
            $stmtLedger->execute([
                'uid' => $userId,
                'amt' => $amount,
                'bal' => $newBal,
                'lm' => (float)$targetUser['locked_margin'],
                'desc' => sprintf('管理员调整资金：%s (原余额 ¥%s -> 新余额 ¥%s)', $reason, number_format($curBal, 2), number_format($newBal, 2))
            ]);

            // 审计
            $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'ADJUST_USER_FUND\', \'USER\', :t, :d)');
            $stmtLog->execute(['a' => $admin['id'], 't' => (string)$userId, 'd' => json_encode(['amount' => $amount, 'reason' => $reason, 'user' => $targetUser['username']])]);

            DB::commit();
            jsonResponse(['balance' => $newBal], '用户资金调整成功');
        } catch (Exception $e) {
            DB::rollBack();
            errorResponse('资金调整失败，请稍后重试或联系管理员', 500);
        }
        break;

    case 'user_toggle_status':
        $userId = (int)($input['user_id'] ?? 0);
        $status = trim($input['status'] ?? 'active');

        if (!in_array($status, ['active', 'banned'])) {
            errorResponse('状态只能为 active 或 banned');
        }
        if ($userId === $admin['id']) {
            errorResponse('不能封禁管理员自身账号');
        }

        $stmtUp = $db->prepare('UPDATE users SET status = :s WHERE id = :id');
        $stmtUp->execute(['s' => $status, 'id' => $userId]);

        // 审计
        $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'TOGGLE_USER_STATUS\', \'USER\', :t, :d)');
        $stmtLog->execute(['a' => $admin['id'], 't' => (string)$userId, 'd' => json_encode(['status' => $status])]);

        jsonResponse(null, '用户状态已更新');
        break;

    case 'market_toggle_pause':
        $stmtSt = $db->query('SELECT is_paused FROM market_state WHERE id = 1');
        $val = $stmtSt->fetchColumn();
        $curPause = filter_var($val, FILTER_VALIDATE_BOOLEAN);
        $newPause = !$curPause;

        $stmtUp = $db->prepare('UPDATE market_state SET is_paused = :p::boolean WHERE id = 1');
        $stmtUp->execute(['p' => $newPause ? 'true' : 'false']);

        // 审计
        $stmtLog = $db->prepare('INSERT INTO admin_logs (admin_id, action, target_type, target_id, details_json) VALUES (:a, \'TOGGLE_MARKET_PAUSE\', \'MARKET\', \'1\', :d)');
        $stmtLog->execute(['a' => $admin['id'], 'd' => json_encode(['paused' => $newPause])]);

        jsonResponse(['is_paused' => $newPause], $newPause ? '市场模拟已暂停' : '市场模拟已恢复运行');
        break;

    case 'logs':
        $stmt = $db->query('
            SELECT l.id, u.username AS admin_name, l.action, l.target_type, l.target_id, l.details_json,
                   TO_CHAR(l.created_at, \'YYYY-MM-DD HH24:MI:SS\') AS log_time
            FROM admin_logs l
            JOIN users u ON l.admin_id = u.id
            ORDER BY l.created_at DESC LIMIT 100
        ');
        jsonResponse(['logs' => $stmt->fetchAll()]);
        break;

    default:
        errorResponse('未知的管理操作', 404);
        break;
}
