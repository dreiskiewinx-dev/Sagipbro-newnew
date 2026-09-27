<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	requireApiLogin();
	$stmt = $conn->query(
		"SELECT id, name, category, unit, stock, low_stock_threshold, location, notes, status, created_at, updated_at,
				CASE WHEN stock = 0 THEN 'Out of stock'
					 WHEN stock <= low_stock_threshold THEN 'Low stock'
					 ELSE 'In stock' END AS stock_status
		 FROM resources WHERE status <> 'Inactive' ORDER BY name"
	);
	jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$name = requiredString($data, 'name', 120);
	$category = requiredString($data, 'category', 80);
	$unit = requiredString($data, 'unit', 30);
	$stock = filter_var($data['stock'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
	$threshold = filter_var($data['low_stock_threshold'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
	if ($stock === false || $threshold === false) {
		jsonResponse(['error' => 'Stock values must be non-negative integers.'], 422);
	}
	$status = in_array($data['status'] ?? 'Available', ['Available', 'Inactive'], true) ? $data['status'] : 'Available';
	$stmt = $conn->prepare('INSERT INTO resources (name, category, unit, stock, low_stock_threshold, location, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
	$stmt->execute([$name, $category, $unit, $stock, $threshold, trim((string) ($data['location'] ?? '')) ?: null, trim((string) ($data['notes'] ?? '')) ?: null, $status]);
	$id = (int) $conn->lastInsertId();
	logActivity($conn, 'create', 'resource', $id, ['name' => $name]);
	jsonResponse(['id' => $id, 'message' => 'Resource created.'], 201);
}

$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
	$name = requiredString($data, 'name', 120);
	$category = requiredString($data, 'category', 80);
	$unit = requiredString($data, 'unit', 30);
	$stock = filter_var($data['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
	$threshold = filter_var($data['low_stock_threshold'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
	if ($stock === false || $threshold === false) {
		jsonResponse(['error' => 'Stock values must be non-negative integers.'], 422);
	}
	$exists = $conn->prepare("SELECT id FROM resources WHERE id = ? AND status <> 'Inactive'");
	$exists->execute([$id]);
	if (!$exists->fetchColumn()) jsonResponse(['error' => 'Resource not found.'], 404);
	$stmt = $conn->prepare('UPDATE resources SET name = ?, category = ?, unit = ?, stock = ?, low_stock_threshold = ?, location = ?, notes = ? WHERE id = ?');
	$stmt->execute([$name, $category, $unit, $stock, $threshold, trim((string) ($data['location'] ?? '')) ?: null, trim((string) ($data['notes'] ?? '')) ?: null, $id]);
	logActivity($conn, 'update', 'resource', $id, ['reason' => trim((string) ($data['reason'] ?? '')) ?: null]);
	jsonResponse(['message' => 'Resource updated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
	$stmt = $conn->prepare("UPDATE resources SET status = 'Inactive' WHERE id = ? AND status <> 'Inactive'");
	$stmt->execute([$id]);
	if (!$stmt->rowCount()) {
		jsonResponse(['error' => 'Resource not found.'], 404);
	}
	logActivity($conn, 'delete', 'resource', $id);
	jsonResponse(['message' => 'Resource archived.']);
}

methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
