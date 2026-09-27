<?php

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	requireApiLogin();
	$stmt = $conn->query("SELECT a.id, a.title, a.body, a.body AS content,
		a.category, a.audience, a.status, a.published_at,
		a.created_by, u.full_name AS author, a.created_at,
		COALESCE(a.updated_at, a.created_at) AS updated_at
		FROM announcements a JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC");
	jsonResponse(['data' => $stmt->fetchAll()]);
}

requireApiLogin(['admin', 'official']);
$data = requestData();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$title = requiredString($data, 'title', 180);
	$body = requiredString($data, 'body', 10000);
	$status = in_array($data['status'] ?? 'Draft', ['Draft', 'Published'], true) ? $data['status'] : 'Draft';
	$category = requiredString($data, 'category', 100);
	$audience = requiredString($data, 'audience', 100);
	$publishedAt = trim((string) ($data['published_at'] ?? ''));
	$publishedAt = $status === 'Published' ? ($publishedAt !== '' ? str_replace('T', ' ', $publishedAt) : date('Y-m-d H:i:s')) : null;
	$stmt = $conn->prepare('INSERT INTO announcements (title, body, category, audience, status, created_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
	$stmt->execute([$title, $body, $category, $audience, $status, currentUserId(), $publishedAt]);
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
		$exists = $conn->prepare("SELECT id FROM announcements WHERE id = ? AND status <> 'Archived'");
		$exists->execute([$id]);
		if (!$exists->fetchColumn()) jsonResponse(['error' => 'Announcement not found or archived.'], 404);
		$stmt = $conn->prepare('UPDATE announcements SET title = ?, body = ?, category = ?, audience = ?, status = ?, published_at = ? WHERE id = ?');
		$stmt->execute([$title, $body, $category, $audience, $status, $publishedAt, $id]);
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
