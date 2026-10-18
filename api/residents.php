<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$fromSql = ' FROM residents r LEFT JOIN households h ON h.id = r.household_id';
	$where = [];
	$params = [];
	if (isset($_GET['id'])) {
		$where[] = 'r.id = ?';
		$params[] = positiveInt($_GET, 'id');
	}
	$query = adminQueryText('q');
	if ($query !== '') {
		$where[] = "(CONCAT_WS(' ', r.first_name, r.last_name) LIKE ? ESCAPE '!' OR h.household_no LIKE ? ESCAPE '!' OR COALESCE(NULLIF(r.address, ''), h.address) LIKE ? ESCAPE '!' OR r.contact_no LIKE ? ESCAPE '!' OR CONCAT('BIN-', LPAD(r.id, GREATEST(4, CHAR_LENGTH(r.id)), '0')) LIKE ? ESCAPE '!')";
		array_push($params, ...array_fill(0, 5, adminLike($query)));
	}
	$status = adminQueryText('status');
	if ($status !== '') {
		$where[] = 'r.status = ?';
		$params[] = $status;
	}
	$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
	$selectSql = 'SELECT r.*, h.household_no, h.address AS household_address, h.barangay' . $fromSql . $whereSql . ' ORDER BY r.last_name, r.first_name, r.id';
	if (isset($_GET['id'])) {
		$stmt = $conn->prepare($selectSql);
		$stmt->execute($params);
		$record = $stmt->fetch();
		if (!$record) jsonResponse(['error' => 'Resident not found.'], 404);
		$record['contact_number'] = $record['contact_no'];
		$record['vulnerable_group'] = $record['vulnerability'];
		$record['address_display'] = $record['address'] ?: ($record['household_address'] ?? '');
		$rank = $conn->prepare('SELECT COUNT(*) FROM residents WHERE (last_name, first_name, id) < (?, ?, ?)');
		$rank->execute([$record['last_name'], $record['first_name'], $record['id']]);
		$perPage = (int) ($_GET['per_page'] ?? 10);
		if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 10;
		jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
	}
	if (adminQueryText('export') === 'csv') {
		$stmt = $conn->prepare($selectSql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll();
		foreach ($rows as &$row) $row['address_display'] = $row['address'] ?: ($row['household_address'] ?? '');
		unset($row);
		adminSendCsv('sagipbro-residents.csv', ['id' => 'Resident ID', 'first_name' => 'First name', 'last_name' => 'Last name', 'household_no' => 'Household', 'address_display' => 'Address', 'birth_date' => 'Birth date', 'sex' => 'Sex', 'contact_no' => 'Contact number', 'vulnerability' => 'Priority group', 'status' => 'Status'], $rows);
	}
	if (adminPaginationRequested()) {
		$result = adminPaginate($conn, $selectSql, 'SELECT COUNT(*)' . $fromSql . $whereSql, $params);
		$result['stats'] = $conn->query("SELECT COUNT(*) AS total, COUNT(DISTINCT household_id) AS households, COALESCE(SUM(status = 'Active'), 0) AS active, COALESCE(SUM(status = 'Active' AND vulnerability IS NOT NULL AND vulnerability <> '' AND vulnerability <> 'None'), 0) AS priority FROM residents")->fetch();
	} else {
		$stmt = $conn->prepare($selectSql);
		$stmt->execute($params);
		$result = ['data' => $stmt->fetchAll()];
	}
	$residents = $result['data'];
	foreach ($residents as &$resident) {
	$resident['contact_number'] = $resident['contact_no'];
	$resident['vulnerable_group'] = $resident['vulnerability'];
	$resident['address_display'] = $resident['address'] ?: ($resident['household_address'] ?? '');
	}
	unset($resident);
	$result['data'] = $residents;
	jsonResponse($result);
}
requireApiLogin(['admin', 'official']);
$data = requestData();
$contactNumber = trim((string) ($data['contact'] ?? $data['contact_number'] ?? ''));
if (strlen($contactNumber) > 11) {
	jsonResponse(['error' => 'Contact number must be 11 characters or fewer.'], 422);
}
$resolveHouseholdId = static function ($value, ?string $address = null, bool $create = false) use ($conn): ?int {
	if ($value === null || $value === '') {
	return null;
	}
	if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
		$stmt = $conn->prepare('SELECT id FROM households WHERE id = ?');
		$stmt->execute([(int) $value]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (int) $id;
	}
	$householdNo = trim((string) $value);
	$stmt = $conn->prepare('SELECT id FROM households WHERE household_no = ?');
	$stmt->execute([$householdNo]);
	$id = $stmt->fetchColumn();
	if ($id === false && $create) {
		$stmt = $conn->prepare('INSERT INTO households (household_no, address, barangay) VALUES (?, ?, ?)');
		$stmt->execute([$householdNo, $address ?: 'Address not recorded', 'Bonuan Binloc']);
		return (int) $conn->lastInsertId();
	}
	return $id === false ? null : (int) $id;
};
$parseName = static function (string $fullName): array {
	$parts = preg_split('/\s+/', trim($fullName), 2);
	return [$parts[0] ?? '', $parts[1] ?? ''];
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$firstName = requiredString($data, 'first_name', 100);
	$lastName = requiredString($data, 'last_name', 100);
	$gender = $data['sex'] ?? $data['gender'] ?? '';
	if ($gender === 'Prefer not to say') {
	$gender = 'Other';
	}
	if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
	jsonResponse(['error' => 'Invalid sex.'], 422);
	}
	$address = requiredString($data, 'address', 255);
	$householdId = $resolveHouseholdId($data['household_id'] ?? null, $address, true);
	if (!empty($data['household_id']) && $householdId === null) {
	jsonResponse(['error' => 'Household not found.'], 422);
	}
	$stmt = $conn->prepare(
	'INSERT INTO residents (household_id, first_name, last_name, birth_date, sex, contact_no, address, vulnerability, status)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'Active\')'
	);
	$stmt->execute([
	$householdId,
	$firstName,
	$lastName,
	trim((string) ($data['birth_date'] ?? '')) ?: null,
	$gender,
	$contactNumber ?: null,
	$address,
	$data['priority_group'] ?? $data['vulnerable_group'] ?? 'None'
	]);
	$id = (int) $conn->lastInsertId();
	logActivity($conn, 'create', 'resident', $id);
	jsonResponse(['id' => $id, 'message' => 'Resident registered.'], 201);
}
$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
	if (!empty($data['first_name']) || !empty($data['last_name'])) {
	$firstName = requiredString($data, 'first_name', 100);
	$lastName = requiredString($data, 'last_name', 100);
	} elseif (!empty($data['full_name'])) {
	[$firstName, $lastName] = $parseName(requiredString($data, 'full_name', 200));
	} else {
	$firstName = requiredString($data, 'first_name', 100);
	$lastName = requiredString($data, 'last_name', 100);
	}
	$sex = $data['sex'] ?? '';
	if (!in_array($sex, ['Male', 'Female', 'Other'], true)) jsonResponse(['error' => 'Invalid sex.'], 422);
	$address = requiredString($data, 'address', 255);
	$householdId = $resolveHouseholdId($data['household_id'] ?? null, $address, true);
	if (!empty($data['household_id']) && $householdId === null) jsonResponse(['error' => 'Household not found.'], 422);
	$status = $data['status'] ?? 'Active';
	if (!in_array($status, ['Active', 'Inactive'], true)) {
	jsonResponse(['error' => 'Invalid status.'], 422);
	}
	$exists = $conn->prepare('SELECT id FROM residents WHERE id = ?');
	$exists->execute([$id]);
	if (!$exists->fetchColumn()) jsonResponse(['error' => 'Resident not found.'], 404);
	$stmt = $conn->prepare(
	'UPDATE residents SET household_id = ?, first_name = ?, last_name = ?, sex = ?, birth_date = ?, contact_no = ?, address = ?, vulnerability = ?, status = ? WHERE id = ?'
	);
	$stmt->execute([
	$householdId,
	$firstName,
	$lastName,
	$sex,
	trim((string) ($data['birth_date'] ?? '')) ?: null,
	$contactNumber ?: null,
	$address,
	$data['priority_group'] ?? $data['vulnerable_group'] ?? 'None',
	$status,
	$id
	]);
	logActivity($conn, 'update', 'resident', $id);
	jsonResponse(['message' => 'Resident updated.']);
}
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
	$stmt = $conn->prepare("UPDATE residents SET status = 'Inactive' WHERE id = ? AND status <> 'Inactive'");
	$stmt->execute([$id]);
	if (!$stmt->rowCount()) {
	jsonResponse(['error' => 'Resident not found.'], 404);
	}
	logActivity($conn, 'delete', 'resident', $id);
	jsonResponse(['message' => 'Resident deactivated.']);
}
methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
