<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $displayStatus = "CASE WHEN status = 'Closed' THEN 'Closed' WHEN occupants >= capacity THEN 'Full' WHEN occupants >= capacity * 0.85 THEN 'Near capacity' ELSE 'Open' END";
    $where = [];
    $params = [];
    $recordId = isset($_GET['id']) ? positiveInt($_GET, 'id') : null;
    if ($recordId !== null) {
        $where[] = 'id = ?';
        $params[] = $recordId;
    } else {
        if (($query = adminQueryText('q')) !== '') {
            $where[] = "CONCAT_WS(' ', name, address, contact_person, contact_number, CONCAT('EC-', LPAD(id, 3, '0')), id) LIKE ? ESCAPE '!'";
            $params[] = adminLike($query);
        }
        if (($status = adminQueryText('status', 30)) !== '') {
            $where[] = "($displayStatus) = ?";
            $params[] = $status;
        }
        // All facilities in this deployment belong to the same service area.
        if (($area = adminQueryText('area', 80)) !== '' && $area !== 'Bonuan Binloc') {
            $where[] = '1 = 0';
        }
    }
    $from = ' FROM evacuation_centers' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $select = 'SELECT id, name, address AS location, capacity, occupants, contact_person AS contact, contact_number AS phone, notes, status, updated_at' . $from . ' ORDER BY name, id';
    if ($recordId !== null) {
        $stmt = $conn->prepare($select);
        $stmt->execute($params);
        $record = $stmt->fetch();
        if (!$record) jsonResponse(['error' => 'Center not found.'], 404);
        $rank = $conn->prepare('SELECT COUNT(*) FROM evacuation_centers WHERE name < ? OR (name = ? AND id < ?)');
        $rank->execute([$record['name'], $record['name'], $recordId]);
        $perPage = 10;
        jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
    }
    if (($_GET['export'] ?? '') === 'print') {
        $statement = $conn->prepare($select);
        $statement->execute($params);
        adminSendPrint('Evacuation center occupancy', ['name' => 'Center', 'location' => 'Location', 'capacity' => 'Capacity', 'occupants' => 'Occupants', 'status' => 'Status', 'contact' => 'Contact person', 'phone' => 'Contact number'], $statement);
    }
    if (adminPaginationRequested()) {
        $result = adminPaginate($conn, $select, 'SELECT COUNT(*)' . $from, $params);
        $stats = $conn->prepare("SELECT COALESCE(SUM(($displayStatus) = 'Full'), 0) AS full" . $from);
        $stats->execute($params);
        $result['stats'] = $stats->fetch();
        jsonResponse($result);
    }
    $stmt = $conn->prepare($select);
    $stmt->execute($params);
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
    $stmt->execute([$name, $location, $capacity, $occupants, requiredString($data, 'contact_person', 150), requiredString($data, 'contact_number', 11), trim((string) ($data['notes'] ?? '')) ?: null, $status]);
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
    $stmt->execute([$name, $location, $capacity, $occupants, requiredString($data, 'contact_person', 150), requiredString($data, 'contact_number', 11), trim((string) ($data['notes'] ?? '')) ?: null, $status, $id]);
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
