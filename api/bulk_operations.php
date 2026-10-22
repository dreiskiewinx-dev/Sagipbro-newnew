<?php
declare(strict_types=1);

/** A safe, actionable conflict for the bulk endpoint to report after rolling back. */
final class SagipbroBulkConflict extends RuntimeException
{
    public readonly int $status;
    public readonly array $failed_ids;

    public function __construct(string $message, array $failedIds = [])
    {
        parent::__construct($message, 409);
        $this->status = 409;
        $ids = array_values(array_unique(array_map('intval', $failedIds)));
        sort($ids, SORT_NUMERIC);
        $this->failed_ids = $ids;
    }
}

/** Only these hardcoded identifiers and existing, history-preserving actions are supported. */
function sagipbroBulkModules(): array
{
    return [
        'resources' => ['table' => 'resources', 'entity' => 'resource', 'actions' => ['archive', 'restore']],
        'centers' => ['table' => 'evacuation_centers', 'entity' => 'evacuation_center', 'actions' => ['close', 'reopen']],
        'distributions' => ['table' => 'distributions', 'entity' => 'distribution', 'actions' => ['complete']],
        'residents' => ['table' => 'residents', 'entity' => 'resident', 'actions' => ['deactivate', 'activate']],
        'volunteers' => ['table' => 'volunteers', 'entity' => 'volunteer', 'actions' => ['deactivate', 'activate']],
        'announcements' => ['table' => 'announcements', 'entity' => 'announcement', 'actions' => ['archive', 'delete', 'restore']],
        'messages' => ['table' => 'contact_messages', 'entity' => 'contact_message', 'actions' => ['mark_read', 'mark_unread', 'resolve']],
        'users' => ['table' => 'users', 'entity' => 'user', 'actions' => ['deactivate', 'activate']],
    ];
}

/** The caller already holds each selected center's parent-row lock. */
function sagipbroBulkGuardCenterClose(PDO $db, array $records): void
{
    $ids = [];
    $blocked = [];
    foreach ($records as $record) {
        $id = (int) $record['id'];
        $ids[] = $id;
        $occupants = $record['occupants'] ?? $record['current_occupants'] ?? null;
        if ($occupants === null) throw new RuntimeException('Center occupancy information is unavailable.');
        if ((int) $occupants > 0) $blocked[] = $id;
    }
    if (!$ids) return;

    // The project has original and legacy evacuation column names. Never guess a request identifier.
    $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evacuees'")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('center_id', $columns, true) && in_array('checked_out_at', $columns, true)) {
        $centerColumn = 'center_id';
        $checkoutColumn = 'checked_out_at';
    } elseif (in_array('evacuation_center_id', $columns, true) && in_array('check_out', $columns, true)) {
        $centerColumn = 'evacuation_center_id';
        $checkoutColumn = 'check_out';
    } else {
        throw new RuntimeException('Active evacuation records cannot be verified.');
    }
    $marks = implode(', ', array_fill(0, count($ids), '?'));
    // A current locking read also catches active evacuees when a stored occupancy count is stale.
    $active = $db->prepare("SELECT {$centerColumn} FROM evacuees
        WHERE {$centerColumn} IN ({$marks}) AND {$checkoutColumn} IS NULL
        ORDER BY {$centerColumn}, id FOR UPDATE");
    foreach ($ids as $position => $id) $active->bindValue($position + 1, $id, PDO::PARAM_INT);
    $active->execute();
    $blocked = array_merge($blocked, array_map('intval', $active->fetchAll(PDO::FETCH_COLUMN)));
    if ($blocked) {
        throw new SagipbroBulkConflict('Selected evacuation centers still have occupants or active evacuees. Check them out before closing the centers.', $blocked);
    }
}

/**
 * Apply only approved state changes to records already validated and locked by the endpoint.
 * The caller owns authentication, the transaction, all audit entries, commit and rollback.
 * For users the caller must lock ALL users in ID order before selecting the requested rows.
 *
 * @return array{changed_ids: array, details_by_id: array, message: string}
 */
function sagipbroBulkApply(PDO $db, string $module, string $action, array $records, int $actorId): array
{
    $configuration = sagipbroBulkModules()[$module] ?? null;
    if ($configuration === null || !in_array($action, $configuration['actions'], true)) {
        throw new InvalidArgumentException('This bulk action is not supported for the selected module.');
    }
    if (!$db->inTransaction()) throw new LogicException('Bulk changes require an active transaction.');
    usort($records, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

    $statuses = match ($module) {
        'resources' => ['Available', 'Inactive'],
        'centers' => ['Open', 'Closed'],
        'distributions' => ['Completed', 'Pending review'],
        'volunteers' => ['Active', 'Deployed', 'Inactive'],
        'announcements' => ['Draft', 'Published', 'Archived'],
        'messages' => ['Unread', 'Read', 'Resolved'],
        default => ['Active', 'Inactive'],
    };
    $invalid = [];
    foreach ($records as $record) {
        if (!in_array($record['status'] ?? null, $statuses, true)) $invalid[] = (int) $record['id'];
    }
    if ($invalid) {
        throw new SagipbroBulkConflict('Some selected records have an unsupported status. Review them individually before continuing.', $invalid);
    }

    if ($module === 'centers' && $action === 'close') sagipbroBulkGuardCenterClose($db, $records);
    if ($module === 'volunteers' && $action === 'deactivate') {
        $deployed = array_values(array_filter($records, static fn (array $record): bool => $record['status'] === 'Deployed'));
        if ($deployed) {
            throw new SagipbroBulkConflict('Selected volunteers are currently deployed. End their deployments before deactivating them.', array_column($deployed, 'id'));
        }
    }
    if ($module === 'announcements' && $action === 'restore') {
        $notArchived = array_values(array_filter($records, static fn (array $record): bool => $record['status'] !== 'Archived'));
        if ($notArchived) {
            throw new SagipbroBulkConflict('Only archived announcements can be restored as drafts.', array_column($notArchived, 'id'));
        }
    }
    if ($module === 'users' && $action === 'deactivate') {
        $selectedIds = array_map('intval', array_column($records, 'id'));
        if (in_array($actorId, $selectedIds, true)) {
            throw new SagipbroBulkConflict('You cannot deactivate the account you are currently using. Remove your account from the selection.', [$actorId]);
        }
        // The endpoint's all-user lock serializes this invariant across overlapping bulk operations.
        $activeAdmins = array_map('intval', $db->query("SELECT id FROM users
            WHERE role = 'admin' AND status = 'Active' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN));
        $selectedAdmins = array_values(array_intersect($activeAdmins, $selectedIds));
        if ($selectedAdmins && count($activeAdmins) === count($selectedAdmins)) {
            throw new SagipbroBulkConflict('At least one active administrator must remain. Remove an administrator from the selection.', $selectedAdmins);
        }
    }

    $targetStatus = match ($module) {
        'resources' => $action === 'archive' ? 'Inactive' : 'Available',
        'centers' => $action === 'close' ? 'Closed' : 'Open',
        'distributions' => 'Completed',
        'announcements' => $action === 'archive' ? 'Archived' : 'Draft',
        'messages' => ['mark_read' => 'Read', 'mark_unread' => 'Unread', 'resolve' => 'Resolved'][$action],
        default => $action === 'deactivate' ? 'Inactive' : 'Active',
    };
    $deleting = $module === 'announcements' && $action === 'delete';
    $changedIds = [];
    $details = [];
    foreach ($records as $record) {
        // Already active deployed volunteers stay deployed rather than losing their assignment state.
        if ($module === 'volunteers' && $action === 'activate' && $record['status'] === 'Deployed') continue;
        if (!$deleting && $record['status'] === $targetStatus) continue;
        $id = (int) $record['id'];
        $changedIds[] = $id;
        $details[$id] = $deleting
            ? ['previous_status' => $record['status'], 'deleted' => true]
            : ['previous_status' => $record['status'], 'status' => $targetStatus];
    }
    if ($changedIds) {
        $marks = implode(', ', array_fill(0, count($changedIds), '?'));
        $table = $configuration['table'];
        $statement = $db->prepare($deleting
            ? "DELETE FROM {$table} WHERE id IN ({$marks})"
            : "UPDATE {$table} SET status = ? WHERE id IN ({$marks})");
        $position = 1;
        if (!$deleting) $statement->bindValue($position++, $targetStatus, PDO::PARAM_STR);
        foreach ($changedIds as $id) $statement->bindValue($position++, $id, PDO::PARAM_INT);
        $statement->execute();
        if ($statement->rowCount() !== count($changedIds)) {
            throw new SagipbroBulkConflict('Some selected records changed before the action could finish. Refresh the list and try again.', $changedIds);
        }
        if ($module === 'users' && $action === 'deactivate') {
            $sessions = $db->prepare("UPDATE user_sessions SET signed_out_at = NOW()
                WHERE user_id IN ({$marks}) AND signed_out_at IS NULL");
            foreach ($changedIds as $position => $id) $sessions->bindValue($position + 1, $id, PDO::PARAM_INT);
            $sessions->execute();
        }
    }
    $messages = [
        'resources' => ['archive' => 'Selected resources archived.', 'restore' => 'Selected resources restored.'],
        'centers' => ['close' => 'Selected evacuation centers closed.', 'reopen' => 'Selected evacuation centers reopened.'],
        'distributions' => ['complete' => 'Selected distributions marked completed. Inventory quantities were not changed.'],
        'residents' => ['deactivate' => 'Selected residents deactivated.', 'activate' => 'Selected residents activated.'],
        'volunteers' => ['deactivate' => 'Selected volunteers deactivated.', 'activate' => 'Selected volunteers activated; current deployments were preserved.'],
        'announcements' => ['archive' => 'Selected announcements archived.', 'delete' => 'Selected announcements permanently deleted.', 'restore' => 'Selected announcements restored as drafts. Review and publish them when ready.'],
        'messages' => ['mark_read' => 'Selected messages marked read.', 'mark_unread' => 'Selected messages marked unread.', 'resolve' => 'Selected messages resolved.'],
        'users' => ['deactivate' => 'Selected users deactivated and their active sessions revoked.', 'activate' => 'Selected users activated.'],
    ];
    return ['changed_ids' => $changedIds, 'details_by_id' => $details, 'message' => $messages[$module][$action]];
}
