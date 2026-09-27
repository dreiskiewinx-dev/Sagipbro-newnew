<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$stmt = $conn->query(
	"SELECT r.*, h.household_no, h.address AS household_address, h.barangay
		 FROM residents r LEFT JOIN households h ON h.id = r.household_id
		 ORDER BY r.last_name, r.first_name"
	);
	$residents = $stmt->fetchAll();
	foreach ($residents as &$resident) {
	$resident['contact_number'] = $resident['contact_no'];
	$resident['vulnerable_group'] = $resident['vulnerability'];
	$resident['address_display'] = $resident['address'] ?: ($resident['household_address'] ?? '');
	}
	unset($resident);
	jsonResponse(['data' => $residents]);
}
requireApiLogin(['admin', 'official']);
$data = requestData();
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
	$data['contact'] ?? $data['contact_number'] ?? null,
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
	$data['contact'] ?? $data['contact_number'] ?? null,
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
