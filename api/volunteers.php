<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin();

$conn->exec("CREATE TABLE IF NOT EXISTS volunteers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    contact VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    availability VARCHAR(80) NOT NULL,
    skills VARCHAR(255) NOT NULL,
    assignment VARCHAR(120) NULL,
    notes TEXT NULL,
    status ENUM('Active', 'Deployed', 'Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = [];
    $params = [];
    if (isset($_GET['id'])) {
        $where[] = 'id = ?';
        $params[] = positiveInt($_GET, 'id');
    }
    $query = adminQueryText('q');
    if ($query !== '') {
        $where[] = "(full_name LIKE ? ESCAPE '!' OR skills LIKE ? ESCAPE '!' OR assignment LIKE ? ESCAPE '!' OR contact LIKE ? ESCAPE '!' OR CONCAT('VOL-', LPAD(id, GREATEST(4, CHAR_LENGTH(id)), '0')) LIKE ? ESCAPE '!')";
        array_push($params, ...array_fill(0, 5, adminLike($query)));
    }
    $status = adminQueryText('status');
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $selectSql = 'SELECT * FROM volunteers' . $whereSql . ' ORDER BY full_name, id';
    if (isset($_GET['id'])) {
        $stmt = $conn->prepare($selectSql);
        $stmt->execute($params);
        $record = $stmt->fetch();
        if (!$record) jsonResponse(['error' => 'Volunteer not found.'], 404);
        $rank = $conn->prepare('SELECT COUNT(*) FROM volunteers WHERE (full_name, id) < (?, ?)');
        $rank->execute([$record['full_name'], $record['id']]);
        $perPage = 10;
        jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
    }
    if (adminQueryText('export') === 'csv') {
        $stmt = $conn->prepare($selectSql);
        $stmt->execute($params);
        adminSendCsv('sagipbro-volunteers.csv', ['id' => 'Volunteer ID', 'full_name' => 'Full name', 'skills' => 'Skills', 'assignment' => 'Assignment', 'contact' => 'Contact number', 'email' => 'Email', 'availability' => 'Availability', 'status' => 'Status', 'notes' => 'Notes'], $stmt->fetchAll());
    }
    if (adminPaginationRequested()) {
        $result = adminPaginate($conn, $selectSql, 'SELECT COUNT(*) FROM volunteers' . $whereSql, $params);
        $result['stats'] = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Active'), 0) AS active, COALESCE(SUM(status = 'Deployed'), 0) AS deployed FROM volunteers")->fetch();
        jsonResponse($result);
    }
    $stmt = $conn->prepare($selectSql);
    $stmt->execute($params);
    jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();
$email = trim((string) ($data['email'] ?? ''));
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    jsonResponse(['error' => 'Enter a valid email address.'], 422);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare('INSERT INTO volunteers (full_name, contact, email, availability, skills, assignment, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'Active\')');
    $stmt->execute([
        requiredString($data, 'full_name', 150), requiredString($data, 'contact', 11),
        $email ?: null, requiredString($data, 'availability', 80), requiredString($data, 'skills', 255),
        $data['assignment'] ?? null, $data['notes'] ?? null
    ]);
    $id = (int) $conn->lastInsertId();
    logActivity($conn, 'create', 'volunteer', $id);
    jsonResponse(['id' => $id, 'message' => 'Volunteer created.'], 201);
}

$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $status = in_array($data['status'] ?? 'Active', ['Active', 'Deployed', 'Inactive'], true) ? $data['status'] : 'Active';
    $exists = $conn->prepare('SELECT id FROM volunteers WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) jsonResponse(['error' => 'Volunteer not found.'], 404);
    $stmt = $conn->prepare('UPDATE volunteers SET full_name = ?, contact = ?, email = ?, availability = ?, skills = ?, assignment = ?, notes = ?, status = ? WHERE id = ?');
    $stmt->execute([
        requiredString($data, 'full_name', 150), requiredString($data, 'contact', 11), $email ?: null,
        requiredString($data, 'availability', 80), requiredString($data, 'skills', 255), $data['assignment'] ?? null,
        $data['notes'] ?? null, $status, $id
    ]);
    logActivity($conn, 'update', 'volunteer', $id);
    jsonResponse(['message' => 'Volunteer updated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $stmt = $conn->prepare("UPDATE volunteers SET status = 'Inactive' WHERE id = ? AND status <> 'Inactive'");
    $stmt->execute([$id]);
    if (!$stmt->rowCount()) jsonResponse(['error' => 'Volunteer not found or already inactive.'], 404);
    logActivity($conn, 'delete', 'volunteer', $id);
    jsonResponse(['message' => 'Volunteer deactivated.']);
}
methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
