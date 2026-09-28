<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    try {
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
