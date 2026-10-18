<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $select = 'SELECT id, name, email, phone, sitio, subject, message, status, created_at FROM contact_messages';
        if (isset($_GET['id'])) {
            $id = positiveInt($_GET, 'id');
            $statement = $conn->prepare($select . ' WHERE id = ?');
            $statement->execute([$id]);
            $record = $statement->fetch();
            if (!$record) jsonResponse(['error' => 'Message not found.'], 404);
            $rank = $conn->prepare('SELECT COUNT(*) FROM contact_messages WHERE created_at > ? OR (created_at = ? AND id > ?)');
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
                $where[] = "(name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR sitio LIKE ? ESCAPE '!' OR subject LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!')";
                $params = array_fill(0, 6, adminLike($search));
            }
            $status = adminQueryText('status', 30);
            if ($status !== '') {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            $conditions = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $result = adminPaginate($conn, $select . $conditions . ' ORDER BY created_at DESC, id DESC', 'SELECT COUNT(*) FROM contact_messages' . $conditions, $params);
            $summary = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Unread'), 0) AS unread FROM contact_messages")->fetch();
            $result['summary'] = ['total' => (int) $summary['total'], 'unread' => (int) $summary['unread']];
            jsonResponse($result);
        }
        $messages = $conn->query('SELECT id, name, email, phone, sitio, subject, message, status, created_at FROM contact_messages ORDER BY created_at DESC, id DESC')->fetchAll();
        jsonResponse(['data' => $messages]);
    } catch (Throwable $e) {
        error_log('Messages API unavailable (' . get_class($e) . ').');
        jsonResponse(['error' => 'Messages temporarily unavailable.'], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = requestData();
    $id = positiveInt($data, 'id');
    $status = (string) ($data['status'] ?? '');
    if (!in_array($status, ['Unread', 'Read', 'Resolved'], true)) {
        jsonResponse(['error' => 'Invalid message status.'], 422);
    }

    $statement = $conn->prepare('UPDATE contact_messages SET status = ? WHERE id = ?');
    $statement->execute([$status, $id]);
    if (!$statement->rowCount()) {
        $exists = $conn->prepare('SELECT id FROM contact_messages WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            jsonResponse(['error' => 'Message not found.'], 404);
        }
    }

    logActivity($conn, 'update_status', 'contact_message', $id, ['status' => $status]);
    jsonResponse(['message' => "Message marked {$status}.", 'status' => $status]);
}

methodNotAllowed(['GET', 'PUT']);
