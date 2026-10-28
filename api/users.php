<?php

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin']);
require_once __DIR__ . '/../config/password_policy.php';

function requireSafeNewPassword($password): void
{
    try {
        $error = sagipbroPasswordPolicyError($password);
    } catch (PasswordScreeningUnavailable $e) {
        jsonResponse(['error' => $e->getMessage()], 503);
    }
    if ($error !== null) jsonResponse(['error' => $error], 422);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $onlineSql = "(u.status = 'Active' AND EXISTS (SELECT 1 FROM user_sessions s WHERE s.user_id = u.id AND s.signed_out_at IS NULL AND s.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)))";
    $where = [];
    $params = [];
    if (isset($_GET['id'])) {
        $where[] = 'u.id = ?';
        $params[] = positiveInt($_GET, 'id');
    }
    $query = adminQueryText('q');
    if ($query !== '') {
        $where[] = "(u.full_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR CONCAT('USR-', LPAD(u.id, GREATEST(4, CHAR_LENGTH(u.id)), '0')) LIKE ? ESCAPE '!')";
        array_push($params, ...array_fill(0, 4, adminLike($query)));
    }
    $role = adminQueryText('role');
    if ($role !== '') {
        $where[] = 'u.role = ?';
        $params[] = ['Administrator' => 'admin', 'Barangay Official' => 'official', 'Volunteer' => 'volunteer', 'Resident' => 'resident'][$role] ?? $role;
    }
    $status = adminQueryText('status');
    if ($status !== '') {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }
    $presence = adminQueryText('presence');
    if ($presence !== '') {
        $where[] = $onlineSql . ' = ?';
        $params[] = strcasecmp($presence, 'Online') === 0 ? 1 : 0;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $selectSql = "SELECT u.id, u.full_name, u.username, u.email, u.role, u.status, u.force_password_change,
                u.last_login_at, u.created_at, u.updated_at,
                COALESCE((SELECT MAX(s.last_seen_at) FROM user_sessions s WHERE s.user_id = u.id), u.last_login_at) AS last_seen_at,
                CASE WHEN $onlineSql THEN 1 ELSE 0 END AS is_online
         FROM users u" . $whereSql . ' ORDER BY u.full_name, u.id';
    if (isset($_GET['id'])) {
        $stmt = $conn->prepare($selectSql);
        $stmt->execute($params);
        $record = $stmt->fetch();
        if (!$record) jsonResponse(['error' => 'User not found.'], 404);
        $rank = $conn->prepare('SELECT COUNT(*) FROM users WHERE (full_name, id) < (?, ?)');
        $rank->execute([$record['full_name'], $record['id']]);
        $perPage = 10;
        jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
    }
    if (adminQueryText('export') === 'csv') {
        $stmt = $conn->prepare($selectSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) $row['presence'] = (int) $row['is_online'] === 1 ? 'Online' : 'Offline';
        unset($row);
        adminSendCsv('sagipbro-users.csv', ['id' => 'User ID', 'full_name' => 'Full name', 'username' => 'Username', 'role' => 'Role', 'email' => 'Email', 'last_login_at' => 'Last login', 'presence' => 'Sign-in status', 'status' => 'Account status'], $rows);
    }
    if (adminPaginationRequested()) {
        $result = adminPaginate($conn, $selectSql, 'SELECT COUNT(*) FROM users u' . $whereSql, $params);
        $result['stats'] = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(u.role IN ('admin', 'official')), 0) AS privileged, COALESCE(SUM($onlineSql), 0) AS online, COALESCE(SUM(u.status = 'Inactive'), 0) AS inactive FROM users u")->fetch();
        jsonResponse($result);
    }
    $stmt = $conn->prepare($selectSql);
    $stmt->execute($params);
    jsonResponse(['data' => $stmt->fetchAll()]);
}

$data = requestData();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = requiredString($data, 'full_name', 150);
    $username = requiredString($data, 'username', 80);
    $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = $data['password'] ?? '';
    $role = $data['role'] ?? 'resident';
    if ($email === false || !in_array($role, ['admin', 'official', 'volunteer', 'resident'], true)) {
        jsonResponse(['error' => 'Enter a valid email and role.'], 422);
    }
    requireSafeNewPassword($password);
    try {
        $stmt = $conn->prepare('INSERT INTO users (full_name, username, email, password_hash, role, force_password_change) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$fullName, $username, $email, password_hash($password, PASSWORD_DEFAULT), $role, !empty($data['force_password_change']) ? 1 : 0]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Username is already in use.'], 409);
    }
    $id = (int) $conn->lastInsertId();
    logActivity($conn, 'create', 'user', $id, ['role' => $role]);
    jsonResponse(['id' => $id, 'message' => 'User created.'], 201);
}

$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (($data['action'] ?? '') === 'activate') {
        $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE id = ? AND status = 'Inactive'");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            logActivity($conn, 'activate', 'user', $id);
        } else {
            $exists = $conn->prepare('SELECT id FROM users WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) jsonResponse(['error' => 'User not found.'], 404);
        }
        jsonResponse(['message' => 'User account enabled.']);
    }
    if (($data['action'] ?? '') === 'reset_password') {
        $password = $data['password'] ?? '';
        requireSafeNewPassword($password);
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare('UPDATE users SET password_hash = ?, force_password_change = 1, password_changed_at=NOW() WHERE id = ?');
            $stmt->execute([$newHash, $id]);
            if (!$stmt->rowCount()) { $conn->rollBack(); jsonResponse(['error' => 'User not found.'], 404); }
            $conn->prepare('UPDATE user_sessions SET signed_out_at=NOW() WHERE user_id=? AND signed_out_at IS NULL')->execute([$id]);
            logActivity($conn, 'reset-password', 'user', $id);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('Password reset failed (' . get_class($e) . ').');
            jsonResponse(['error' => 'Password reset could not be completed. Please try again later.'], 503);
        }
        jsonResponse(['message' => 'Temporary password issued.']);
    }
    $fullName = requiredString($data, 'full_name', 150);
    $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $role = $data['role'] ?? null;
    $status = $data['status'] ?? null;
    if ($email === false || !in_array($role, ['admin', 'official', 'volunteer', 'resident'], true) || !in_array($status, ['Active', 'Inactive'], true)) {
        jsonResponse(['error' => 'Enter a valid email, role, and status.'], 422);
    }
    $exists = $conn->prepare('SELECT role FROM users WHERE id = ?');
    $exists->execute([$id]);
    $previousRole = $exists->fetchColumn();
    if ($previousRole === false) jsonResponse(['error' => 'User not found.'], 404);
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?');
    $stmt->execute([$fullName, $email, $role, $status, $id]);
    if ($status === 'Inactive' || $role !== $previousRole) {
        $conn->prepare('UPDATE user_sessions SET signed_out_at = NOW() WHERE user_id = ? AND signed_out_at IS NULL')->execute([$id]);
    }
    logActivity($conn, 'update', 'user', $id, ['role' => $role, 'status' => $status]);
    jsonResponse(['message' => 'User updated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?");
    $stmt->execute([$id]);
    $conn->prepare('UPDATE user_sessions SET signed_out_at = NOW() WHERE user_id = ? AND signed_out_at IS NULL')->execute([$id]);
    logActivity($conn, 'delete', 'user', $id);
    jsonResponse(['message' => 'User deactivated.']);
}

methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
