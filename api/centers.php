<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->query("SELECT id, name, address AS location, capacity, occupants, contact_person AS contact, contact_number AS phone, notes, status, updated_at FROM evacuation_centers ORDER BY name");
    jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();
$validStatus = ['Open', 'Closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = requiredString($data, 'name', 150);
    $location = requiredString($data, 'location', 255);
    $capacity = filter_var($data['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $occupants = filter_var($data['occupants'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $status = in_array($data['status'] ?? 'Open', $validStatus, true) ? $data['status'] : 'Open';
    if ($capacity === false || $occupants === false || $occupants > $capacity) jsonResponse(['error' => 'Invalid capacity or occupancy.'], 422);
    $stmt = $conn->prepare('INSERT INTO evacuation_centers (name, address, capacity, occupants, contact_person, contact_number, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $location, $capacity, $occupants, requiredString($data, 'contact_person', 150), requiredString($data, 'contact_number', 30), trim((string) ($data['notes'] ?? '')) ?: null, $status]);
    $id = (int) $conn->lastInsertId();
    logActivity($conn, 'create', 'evacuation_center', $id, ['name' => $name]);
    jsonResponse(['id' => $id, 'message' => 'Evacuation center created.'], 201);
}

$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $name = requiredString($data, 'name', 150);
    $location = requiredString($data, 'location', 255);
    $capacity = filter_var($data['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $occupants = filter_var($data['occupants'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $status = in_array($data['status'] ?? 'Open', $validStatus, true) ? $data['status'] : 'Open';
    if ($capacity === false || $occupants === false || $occupants > $capacity) jsonResponse(['error' => 'Invalid capacity or occupancy.'], 422);
    $exists = $conn->prepare('SELECT id FROM evacuation_centers WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) jsonResponse(['error' => 'Center not found.'], 404);
    $stmt = $conn->prepare('UPDATE evacuation_centers SET name = ?, address = ?, capacity = ?, occupants = ?, contact_person = ?, contact_number = ?, notes = ?, status = ? WHERE id = ?');
    $stmt->execute([$name, $location, $capacity, $occupants, requiredString($data, 'contact_person', 150), requiredString($data, 'contact_number', 30), trim((string) ($data['notes'] ?? '')) ?: null, $status, $id]);
    logActivity($conn, 'update', 'evacuation_center', $id);
    jsonResponse(['message' => 'Evacuation center updated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $stmt = $conn->prepare("UPDATE evacuation_centers SET status = 'Closed' WHERE id = ? AND status <> 'Closed'");
    $stmt->execute([$id]);
    if (!$stmt->rowCount()) jsonResponse(['error' => 'Center not found or already closed.'], 404);
    logActivity($conn, 'delete', 'evacuation_center', $id);
    jsonResponse(['message' => 'Evacuation center closed.']);
}
methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
