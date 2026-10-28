<?php

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official', 'volunteer']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$stmt = $conn->query("SELECT e.*, e.center_name AS name, e.current_occupants AS occupants, GREATEST(e.capacity - e.current_occupants, 0) AS available_capacity,
		CASE WHEN e.status = 'Closed' THEN 'Closed' WHEN e.current_occupants >= e.capacity THEN 'Full' ELSE 'Open' END AS availability
		FROM evacuation_centers e ORDER BY e.center_name");
	jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official', 'volunteer']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$data = requestData();
	$centerId = positiveInt($data, 'center_id');
	$name = requiredString($data, 'name', 150);
	$contact = $data['contact_no'] ?? null;
	$conn->beginTransaction();
	try {
		$centerStmt = $conn->prepare("SELECT capacity, current_occupants AS occupants FROM evacuation_centers WHERE id = ? AND status = 'Open' FOR UPDATE");
		$centerStmt->execute([$centerId]);
		$center = $centerStmt->fetch();
		if (!$center || (int) $center['occupants'] >= (int) $center['capacity']) {
			throw new RuntimeException('Evacuation center is closed or full.');
		}
		$residentId = !empty($data['resident_id']) ? positiveInt($data, 'resident_id') : null;
		$stmt = $conn->prepare('INSERT INTO evacuees (resident_id, evacuation_center_id, check_in, status, remarks) VALUES (?, ?, NOW(), \'Evacuated\', ?)');
		$stmt->execute([$residentId, $centerId, $name . ($contact ? ' / ' . $contact : '')]);
		$conn->prepare('UPDATE evacuation_centers SET current_occupants = current_occupants + 1 WHERE id = ?')->execute([$centerId]);
		$id = (int) $conn->lastInsertId();
		logActivity($conn, 'check-in', 'evacuee', $id, ['center_id' => $centerId]);
		$conn->commit();
		jsonResponse(['id' => $id, 'message' => 'Evacuee checked in.'], 201);
	} catch (Throwable $e) {
		$conn->rollBack();
		jsonResponse(['error' => $e->getMessage()], 422);
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
	$data = requestData();
	$id = positiveInt($data, 'id');
	$conn->beginTransaction();
	try {
		$stmt = $conn->prepare("UPDATE evacuees SET check_out = NOW(), status = 'Returned' WHERE id = ? AND check_out IS NULL");
		$stmt->execute([$id]);
		if (!$stmt->rowCount()) {
			throw new RuntimeException('Active evacuee record not found.');
		}
		$centerStmt = $conn->prepare('SELECT evacuation_center_id FROM evacuees WHERE id = ?');
		$centerStmt->execute([$id]);
		$centerId = (int) $centerStmt->fetchColumn();
		$conn->prepare('UPDATE evacuation_centers SET current_occupants = GREATEST(current_occupants - 1, 0) WHERE id = ?')->execute([$centerId]);
		logActivity($conn, 'check-out', 'evacuee', $id);
		$conn->commit();
		jsonResponse(['message' => 'Evacuee checked out.']);
	} catch (Throwable $e) {
		$conn->rollBack();
		jsonResponse(['error' => $e->getMessage()], 422);
	}
}

methodNotAllowed(['GET', 'POST', 'PUT']);
