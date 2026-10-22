<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bulk_operations.php';

requireApiLogin(['admin']);
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') methodNotAllowed(['POST']);

/** Every failure is all-or-nothing; never imply that other selected rows succeeded. */
function adminBulkError(string $message, int $status, array $ids = [], array $failedIds = []): void
{
    jsonResponse([
        'error' => $message, 'failed_ids' => array_values($failedIds),
        'succeeded_ids' => [], 'affected_ids' => [], 'affected_count' => 0,
        'selected_count' => count($ids), 'rolled_back' => true,
    ], $status);
}

$payload = json_decode((string) file_get_contents('php://input'));
if (!$payload instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
    adminBulkError('Send a valid JSON object containing the module, action, and selected IDs.', 400);
}
$module = $payload->module ?? null;
$action = $payload->action ?? null;
$modules = sagipbroBulkModules();
if (!is_string($module) || !isset($modules[$module]) || !is_string($action)
    || !in_array($action, $modules[$module]['actions'], true)) {
    adminBulkError('This bulk action is not supported for the selected module.', 422);
}
$ids = $payload->ids ?? null;
if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 100) {
    adminBulkError('Select between 1 and 100 records on the current page.', 422);
}
$seen = [];
foreach ($ids as $id) {
    if (!is_int($id) || $id < 1 || $id > 4294967295 || isset($seen[$id])) {
        adminBulkError('Selected IDs must be unique positive integers.', 422);
    }
    $seen[$id] = true;
}
sort($ids, SORT_NUMERIC);
$definition = $modules[$module];
$actorId = (int) currentUserId();

try {
    $conn->beginTransaction();
    if ($module === 'users') {
        // Serialize account-set changes before checking the last active administrator.
        $conn->query('SELECT id, role, status FROM users ORDER BY id FOR UPDATE')->fetchAll();
    }
    // A cached session role alone must not authorize a disabled or demoted account.
    $actor = $conn->prepare('SELECT role, status FROM users WHERE id = ? FOR UPDATE');
    $actor->bindValue(1, $actorId, PDO::PARAM_INT);
    $actor->execute();
    $account = $actor->fetch();
    if (!$account || $account['role'] !== 'admin' || $account['status'] !== 'Active') {
        $conn->rollBack();
        adminBulkError('An active administrator account is required for bulk actions.', 403, $ids);
    }

    $marks = implode(', ', array_fill(0, count($ids), '?'));
    // Table names come exclusively from the server-side capability whitelist.
    $selected = $conn->prepare('SELECT * FROM `' . $definition['table'] . "` WHERE id IN ({$marks}) ORDER BY id FOR UPDATE");
    foreach ($ids as $position => $id) $selected->bindValue($position + 1, $id, PDO::PARAM_INT);
    $selected->execute();
    $records = $selected->fetchAll();
    $foundIds = array_map(static fn (array $record): int => (int) $record['id'], $records);
    $missingIds = array_values(array_diff($ids, $foundIds));
    if ($missingIds) {
        $conn->rollBack();
        adminBulkError('Some selected records no longer exist. No records were changed; refresh the list and try again.', 404, $ids, $missingIds);
    }

    $result = sagipbroBulkApply($conn, $module, $action, $records, $actorId);
    $affectedIds = array_values($result['changed_ids']);
    $unchangedIds = array_values(array_diff($ids, $affectedIds));
    $counts = [
        'selected_count' => count($ids), 'affected_count' => count($affectedIds),
        'unchanged_count' => count($unchangedIds),
    ];
    foreach ($affectedIds as $id) {
        logActivity($conn, $action, $definition['entity'], $id, [
            'bulk' => true, 'module' => $module,
        ] + ($result['details_by_id'][$id] ?? []));
    }
    logActivity($conn, 'bulk_' . $action, $definition['entity'], null, [
        'module' => $module, 'selected_ids' => $ids,
        'affected_ids' => $affectedIds, 'unchanged_ids' => $unchangedIds,
    ] + $counts);
    $conn->commit();
    jsonResponse([
        'module' => $module, 'action' => $action, 'ids' => $ids,
        'affected_ids' => $affectedIds, 'unchanged_ids' => $unchangedIds,
        'succeeded_ids' => $affectedIds, 'failed_ids' => [],
        'message' => $result['message'],
    ] + $counts);
} catch (SagipbroBulkConflict $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    adminBulkError($error->getMessage() . ' No selected records were changed.', $error->status, $ids, $error->failed_ids);
} catch (Throwable $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Administrative bulk action failed (' . get_class($error) . ').');
    $conflict = $error instanceof PDOException && str_starts_with((string) $error->getCode(), '23');
    adminBulkError($conflict
        ? 'Related records prevent this action. No selected records were changed.'
        : 'The bulk action could not be completed. No changes were saved. Refresh the list before retrying.',
        $conflict ? 409 : 500, $ids, $ids);
}
