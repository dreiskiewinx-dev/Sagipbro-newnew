<?php

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->query(
        "SELECT u.id, u.full_name, u.username, u.email, u.role, u.status, u.force_password_change,
                u.last_login_at, u.created_at, u.updated_at,
                COALESCE(MAX(s.last_seen_at), u.last_login_at) AS last_seen_at,
                CASE WHEN u.status = 'Active' AND SUM(
                    CASE WHEN s.signed_out_at IS NULL AND s.last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) THEN 1 ELSE 0 END
                ) > 0 THEN 1 ELSE 0 END AS is_online
         FROM users u
         LEFT JOIN user_sessions s ON s.user_id = u.id
         GROUP BY u.id, u.full_name, u.username, u.email, u.role, u.status, u.force_password_change,
                  u.last_login_at, u.created_at, u.updated_at
         ORDER BY u.full_name"
    );
    jsonResponse(['data' => $stmt->fetchAll()]);
}

$data = requestData();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = requiredString($data, 'full_name', 150);
    $username = requiredString($data, 'username', 80);
    $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string) ($data['password'] ?? '');
    $role = $data['role'] ?? 'resident';
    if ($email === false || strlen($password) < 8 || !in_array($role, ['admin', 'official', 'volunteer', 'resident'], true)) {
        jsonResponse(['error' => 'Enter a valid email, password, and role.'], 422);
    }
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
    if (($data['action'] ?? '') === 'reset_password') {
        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < 8) jsonResponse(['error' => 'Temporary password must contain at least 8 characters.'], 422);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ?, force_password_change = 1 WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        if (!$stmt->rowCount()) jsonResponse(['error' => 'User not found.'], 404);
        logActivity($conn, 'reset-password', 'user', $id);
        jsonResponse(['message' => 'Temporary password issued.']);
    }
    $fullName = requiredString($data, 'full_name', 150);
    $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $role = $data['role'] ?? null;
    $status = $data['status'] ?? null;
    if ($email === false || !in_array($role, ['admin', 'official', 'volunteer', 'resident'], true) || !in_array($status, ['Active', 'Inactive'], true)) {
        jsonResponse(['error' => 'Enter a valid email, role, and status.'], 422);
    }
    $exists = $conn->prepare('SELECT id FROM users WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) jsonResponse(['error' => 'User not found.'], 404);
    $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?');
    $stmt->execute([$fullName, $email, $role, $status, $id]);
    if ($status === 'Inactive') {
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
