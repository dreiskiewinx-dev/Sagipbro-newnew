<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(SAGIPBRO_LOGIN_ROLES, true);
header('Cache-Control: no-store, private');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) methodNotAllowed(['GET', 'POST']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !touchSessionActivity()) jsonResponse(['error' => 'Session expired. Sign in again.'], 401);
    jsonResponse(array_merge($_SESSION['session_timing'], [
        'password_change_required' => passwordChangeRequired(),
        'password_change_url' => appUrl('pages/profile/change-password.php'),
    ]));
} catch (Throwable $e) {
    error_log('Session status unavailable (' . get_class($e) . ').');
    jsonResponse(['error' => 'Session status is temporarily unavailable.'], 503);
}
