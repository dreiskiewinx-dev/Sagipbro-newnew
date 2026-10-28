<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	requireApiLogin(($_GET['lookup'] ?? '') === 'distribution' ? ['admin', 'official', 'volunteer'] : ['admin', 'official']);
	$showArchived = ($_GET['view'] ?? '') === 'archived';
	// Distribution forms only need compact inventory choices, not every resource record.
	if (($_GET['lookup'] ?? '') === 'distribution') {
		$stmt = $conn->query("SELECT id, name, category, unit, stock FROM resources WHERE status <> 'Inactive' ORDER BY name, id");
		jsonResponse(['data' => $stmt->fetchAll()]);
	}
	$stockStatus = "CASE WHEN stock = 0 THEN 'Out of stock' WHEN stock <= low_stock_threshold THEN 'Low stock' ELSE 'In stock' END";
	$where = ["status " . ($showArchived ? "= 'Inactive'" : "<> 'Inactive'")];
	$params = [];
	$recordId = isset($_GET['id']) ? positiveInt($_GET, 'id') : null;
	if ($recordId !== null) {
		$where = ['id = ?'];
		$params = [$recordId];
	} else {
		if (($query = adminQueryText('q')) !== '') {
			$where[] = "CONCAT_WS(' ', id, CONCAT('#', id), name, category, unit, location, notes) LIKE ? ESCAPE '!'";
			$params[] = adminLike($query);
		}
		if (($category = adminQueryText('category', 80)) !== '') {
			$where[] = 'category = ?';
			$params[] = $category;
		}
		if (($status = adminQueryText('status', 30)) !== '') {
			$where[] = "($stockStatus) = ?";
			$params[] = $status;
		}
	}
	$from = ' FROM resources WHERE ' . implode(' AND ', $where);
	$select = "SELECT id, name, category, unit, stock, low_stock_threshold, location, notes, status, created_at, updated_at,
		(status = 'Inactive') AS is_archived, $stockStatus AS stock_status" . $from . ' ORDER BY name, id';
	if ($recordId !== null) {
		$stmt = $conn->prepare($select);
		$stmt->execute($params);
		$record = $stmt->fetch();
		if (!$record) jsonResponse(['error' => 'Resource not found.'], 404);
		$rank = $conn->prepare("SELECT COUNT(*) FROM resources WHERE (status = 'Inactive') = ? AND (name < ? OR (name = ? AND id < ?))");
		$rank->execute([(int) $record['is_archived'], $record['name'], $record['name'], $recordId]);
		$perPage = 10;
		jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
	}
	if (($_GET['export'] ?? '') === 'csv') {
		$stmt = $conn->prepare($select);
		$stmt->execute($params);
		adminSendCsv('sagipbro-resource-inventory-' . date('Y-m-d') . '.csv', [
			'id' => 'ID', 'name' => 'Resource', 'category' => 'Category', 'stock' => 'Available quantity',
			'unit' => 'Unit', 'low_stock_threshold' => 'Low-stock threshold', 'location' => 'Storage location',
			'stock_status' => 'Stock status', 'status' => 'Record status', 'updated_at' => 'Last updated', 'notes' => 'Notes',
		], $stmt);
	}
	if (adminPaginationRequested()) {
		$result = adminPaginate($conn, $select, 'SELECT COUNT(*)' . $from, $params);
		$stats = $conn->prepare('SELECT COALESCE(SUM(stock <= low_stock_threshold), 0) AS attention' . $from);
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
	if (($data['action'] ?? '') === 'restore') {
		$stmt = $conn->prepare("UPDATE resources SET status = 'Available' WHERE id = ? AND status = 'Inactive'");
		$stmt->execute([$id]);
		if (!$stmt->rowCount()) jsonResponse(['error' => 'Archived resource not found.'], 404);
		logActivity($conn, 'restore', 'resource', $id);
		jsonResponse(['message' => 'Resource restored to active inventory.']);
	}
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
	logActivity($conn, 'archive', 'resource', $id);
	jsonResponse(['message' => 'Resource archived.']);
}

methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
