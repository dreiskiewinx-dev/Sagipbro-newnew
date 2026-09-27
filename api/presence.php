<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official', 'volunteer', 'resident']);
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    methodNotAllowed(['POST']);
}

$userId = (int) currentUserId();
$sessionHash = hash('sha256', session_id());
session_write_close();

try {
    $statement = $conn->prepare(
        'INSERT INTO user_sessions (session_hash, user_id, last_seen_at, signed_out_at)
         VALUES (?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = NOW(), signed_out_at = NULL'
    );
    $statement->execute([$sessionHash, $userId]);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('Presence heartbeat unavailable (' . get_class($e) . ').');
    jsonResponse(['error' => 'Presence temporarily unavailable.'], 503);
}
