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
    $stmt = $conn->query('SELECT * FROM volunteers ORDER BY full_name');
    jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare('INSERT INTO volunteers (full_name, contact, email, availability, skills, assignment, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'Active\')');
    $stmt->execute([
        requiredString($data, 'full_name', 150), requiredString($data, 'contact', 30),
        $data['email'] ?? null, requiredString($data, 'availability', 80), requiredString($data, 'skills', 255),
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
        requiredString($data, 'full_name', 150), requiredString($data, 'contact', 30), $data['email'] ?? null,
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
