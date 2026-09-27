<?php

require_once '../../config/session.php';
require_once '../../config/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit('Logout must use POST.');
}
verifyCsrf();

try {
	$sessionHash = hash('sha256', session_id());
	$statement = sagipbroDatabase()->prepare('UPDATE user_sessions SET signed_out_at = NOW(), last_seen_at = NOW() WHERE session_hash = ?');
	$statement->execute([$sessionHash]);
} catch (Throwable $e) {
	error_log('Session presence could not be closed (' . get_class($e) . ').');
}

$_SESSION = [];
session_destroy();
header('Location: ../../login.php');
exit;
