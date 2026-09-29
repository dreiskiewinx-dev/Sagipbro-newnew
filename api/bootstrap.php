<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$conn = sagipbroDatabase();

header('Content-Type: application/json; charset=utf-8');

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData(): array
{
    if ($_POST) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Request body must be valid JSON.'], 400);
    }
    return $data;
}

function requireApiLogin(array $roles = []): void
{
    if (!isLoggedIn()) {
        jsonResponse(['error' => 'Authentication required.'], 401);
    }
    if ($roles && !in_array($_SESSION['role'], $roles, true)) {
        jsonResponse(['error' => 'You are not authorized for this action.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        verifyCsrf();
    }
}

function requiredString(array $data, string $key, int $maxLength = 255): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength) {
        jsonResponse(['error' => "Invalid {$key}."], 422);
    }
    return $value;
}

function positiveInt(array $data, string $key): int
{
    $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false) {
        jsonResponse(['error' => "Invalid {$key}."], 422);
    }
    return $value;
}

function logActivity(PDO $conn, string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $stmt = $conn->prepare(
        'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip_address)'
    );
    $stmt->execute([
        ':user_id' => currentUserId(),
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

function methodNotAllowed(array $methods): void
{
    header('Allow: ' . implode(', ', $methods));
    jsonResponse(['error' => 'Method not allowed.'], 405);
}
