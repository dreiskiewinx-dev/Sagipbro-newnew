<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	requireApiLogin();
	$select = "SELECT a.id, a.title, a.body, a.body AS content,
		a.category, a.audience, a.status, a.published_at, a.priority, a.expires_at,
		a.created_by, u.full_name AS author, a.created_at,
		COALESCE(a.updated_at, a.created_at) AS updated_at
		FROM announcements a JOIN users u ON u.id = a.created_by";
	if (isset($_GET['id'])) {
		$id = positiveInt($_GET, 'id');
		$stmt = $conn->prepare($select . ' WHERE a.id = ?');
		$stmt->execute([$id]);
		$record = $stmt->fetch();
		if (!$record) jsonResponse(['error' => 'Announcement not found.'], 404);
		$rank = $conn->prepare('SELECT COUNT(*) FROM announcements a JOIN users u ON u.id = a.created_by WHERE a.created_at > ? OR (a.created_at = ? AND a.id > ?)');
		$rank->execute([$record['created_at'], $record['created_at'], $id]);
		$perPage = (int) ($_GET['per_page'] ?? 10);
		if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 10;
		jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
	}
	if (adminPaginationRequested()) {
		$where = [];
		$params = [];
		$search = adminQueryText('q');
		if ($search !== '') {
			$where[] = "(a.title LIKE ? ESCAPE '!' OR a.body LIKE ? ESCAPE '!' OR u.full_name LIKE ? ESCAPE '!')";
			$params = array_fill(0, 3, adminLike($search));
		}
		foreach (['category', 'status', 'audience'] as $field) {
			$value = adminQueryText($field, 100);
			if ($value !== '') {
				$where[] = "a.{$field} = ?";
				$params[] = $value;
			}
		}
		$conditions = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$result = adminPaginate($conn, $select . $conditions . ' ORDER BY a.created_at DESC, a.id DESC', 'SELECT COUNT(*) FROM announcements a JOIN users u ON u.id = a.created_by' . $conditions, $params);
		$result['summary'] = ['published' => (int) $conn->query("SELECT COUNT(*) FROM announcements WHERE status = 'Published'")->fetchColumn()];
		jsonResponse($result);
	}
	$stmt = $conn->query($select . ' ORDER BY a.created_at DESC, a.id DESC');
	jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();
$announcementPriority = static function ($value): string {
	if (!in_array($value, ['Normal', 'Urgent', 'High', 'Medium', 'Low'], true)) jsonResponse(['error' => 'Choose a valid announcement priority.'], 422);
	return $value;
};
$announcementExpiry = static function ($value): ?string {
	if ($value === null || $value === '') return null;
	if (!is_string($value)) jsonResponse(['error' => 'Enter a valid expiry date and time.'], 422);
	foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s'] as $format) {
		$date = DateTimeImmutable::createFromFormat('!' . $format, $value);
		if ($date && $date->format($format) === $value) return $date->format('Y-m-d H:i:s');
	}
	jsonResponse(['error' => 'Enter a valid expiry date and time.'], 422);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$title = requiredString($data, 'title', 180);
	$body = requiredString($data, 'body', 10000);
	$status = in_array($data['status'] ?? 'Draft', ['Draft', 'Published'], true) ? $data['status'] : 'Draft';
	$category = requiredString($data, 'category', 100);
	$audience = requiredString($data, 'audience', 100);
	$publishedAt = trim((string) ($data['published_at'] ?? ''));
	$publishedAt = $status === 'Published' ? ($publishedAt !== '' ? str_replace('T', ' ', $publishedAt) : date('Y-m-d H:i:s')) : null;
	$priority = $announcementPriority($data['priority'] ?? 'Normal');
	$expiresAt = $announcementExpiry($data['expires_at'] ?? null);
	$stmt = $conn->prepare('INSERT INTO announcements (title, body, category, audience, status, created_by, published_at, priority, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$stmt->execute([$title, $body, $category, $audience, $status, currentUserId(), $publishedAt, $priority, $expiresAt]);
	$id = (int) $conn->lastInsertId();
	logActivity($conn, $status === 'Published' ? 'publish' : 'create', 'announcement', $id);
	jsonResponse(['id' => $id, 'message' => 'Announcement created.'], 201);
}

$id = positiveInt($data, 'id');
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
	$title = requiredString($data, 'title', 180);
		$body = requiredString($data, 'body', 10000);
		$category = requiredString($data, 'category', 100);
		$audience = requiredString($data, 'audience', 100);
		$status = in_array($data['status'] ?? 'Draft', ['Draft', 'Published', 'Archived'], true) ? $data['status'] : 'Draft';
		$publishedAt = trim((string) ($data['published_at'] ?? ''));
		$publishedAt = $status === 'Published' ? ($publishedAt !== '' ? str_replace('T', ' ', $publishedAt) : date('Y-m-d H:i:s')) : null;
		$exists = $conn->prepare("SELECT id, priority, expires_at FROM announcements WHERE id = ? AND status <> 'Archived'");
		$exists->execute([$id]);
		$current = $exists->fetch();
		if (!$current) jsonResponse(['error' => 'Announcement not found or archived.'], 404);
		$priority = array_key_exists('priority', $data) ? $announcementPriority($data['priority']) : $current['priority'];
		$expiresAt = array_key_exists('expires_at', $data) ? $announcementExpiry($data['expires_at']) : $current['expires_at'];
		$stmt = $conn->prepare('UPDATE announcements SET title = ?, body = ?, category = ?, audience = ?, status = ?, published_at = ?, priority = ?, expires_at = ? WHERE id = ?');
		$stmt->execute([$title, $body, $category, $audience, $status, $publishedAt, $priority, $expiresAt, $id]);
	logActivity($conn, 'update', 'announcement', $id);
	jsonResponse(['message' => 'Announcement updated.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
	$stmt = $conn->prepare("UPDATE announcements SET status = 'Archived' WHERE id = ?");
	$stmt->execute([$id]);
	if (!$stmt->rowCount()) {
		jsonResponse(['error' => 'Announcement not found.'], 404);
	}
	logActivity($conn, 'archive', 'announcement', $id);
	jsonResponse(['message' => 'Announcement archived.']);
}

methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
