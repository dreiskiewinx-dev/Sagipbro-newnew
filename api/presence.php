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

try {
    $statement = $conn->prepare(
        'UPDATE user_sessions SET last_seen_at=NOW()
         WHERE session_hash=? AND user_id=? AND signed_out_at IS NULL'
    );
    $statement->execute([$sessionHash, $userId]);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('Presence heartbeat unavailable (' . get_class($e) . ').');
    jsonResponse(['error' => 'Presence temporarily unavailable.'], 503);
}
