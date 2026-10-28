<?php

require_once __DIR__ . '/bootstrap.php';
requireApiLogin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = [];
    $params = [];
    $recordId = isset($_GET['id']) ? positiveInt($_GET, 'id') : null;
    if ($recordId !== null) {
        $where[] = 'd.id = ?';
        $params[] = $recordId;
    } else {
        if (($query = adminQueryText('q')) !== '') {
            $where[] = "CONCAT_WS(' ', d.id, CONCAT('DST-', LPAD(d.id, 6, '0')), r.name, r.category, d.recipient_name, d.recipient_reference, d.location, u.full_name, d.remarks) LIKE ? ESCAPE '!'";
            $params[] = adminLike($query);
        }
        if (($category = adminQueryText('category', 80)) !== '') {
            $where[] = 'r.category = ?';
            $params[] = $category;
        }
        if (($status = adminQueryText('status', 30)) !== '') {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }
        $period = adminQueryText('period', 20);
        if ($period === 'today') $where[] = 'd.distributed_at >= CURRENT_DATE() AND d.distributed_at < CURRENT_DATE() + INTERVAL 1 DAY';
        elseif ($period === 'previous') $where[] = 'd.distributed_at < CURRENT_DATE()';
    }
    $from = ' FROM distributions d JOIN resources r ON r.id = d.resource_id JOIN users u ON u.id = d.distributed_by'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $select = 'SELECT d.*, d.recipient_reference AS household_id, r.name AS resource_name,
        r.category AS resource_category, r.unit AS resource_unit, u.full_name AS distributed_by_name'
        . $from . ' ORDER BY d.distributed_at DESC, d.id DESC';
    if ($recordId !== null) {
        $stmt = $conn->prepare($select);
        $stmt->execute($params);
        $record = $stmt->fetch();
        if (!$record) jsonResponse(['error' => 'Distribution not found.'], 404);
        $rank = $conn->prepare('SELECT COUNT(*) FROM distributions WHERE distributed_at > ? OR (distributed_at = ? AND id > ?)');
        $rank->execute([$record['distributed_at'], $record['distributed_at'], $recordId]);
        $perPage = 10;
        jsonResponse(['data' => [$record], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
    }

    // Exports contain every matching row, regardless of the currently selected page.
    if (($_GET['export'] ?? '') !== 'csv' && adminPaginationRequested()) {
        $result = adminPaginate($conn, $select, 'SELECT COUNT(*)' . $from, $params);
        $stats = $conn->prepare("SELECT COALESCE(SUM(d.status = 'Pending review'), 0) AS pending" . $from);
        $stats->execute($params);
        $result['stats'] = $stats->fetch();
        jsonResponse($result);
    }
    $stmt = $conn->prepare($select);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sagipbro-distribution-register-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, max-age=0');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            http_response_code(500);
            exit('Unable to prepare the distribution export.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Reference', 'Resource', 'Category', 'Recipient', 'Resident / household ID',
            'Quantity', 'Unit', 'Location', 'Distributed at', 'Distributed by', 'Status', 'Notes',
        ], ',', '"', '');

        $safeCell = static function ($value): string {
            $value = (string) ($value ?? '');
            return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
        };

        foreach ($rows as $row) {
            fputcsv($output, [
                'DST-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT),
                $safeCell($row['resource_name']),
                $safeCell($row['resource_category']),
                $safeCell($row['recipient_name']),
                $safeCell($row['recipient_reference']),
                (string) $row['quantity'],
                $safeCell($row['resource_unit']),
                $safeCell($row['location']),
                (string) $row['distributed_at'],
                $safeCell($row['distributed_by_name']),
                $safeCell($row['status']),
                $safeCell($row['remarks']),
            ], ',', '"', '');
        }
        fclose($output);
        exit;
    }

    jsonResponse(['data' => $rows]);
}

requireApiLogin(['admin', 'official', 'volunteer']);
$data = requestData();
$optional = static function ($value, int $maxLength): ?string {
    $value = trim((string) $value);
    if (strlen($value) > $maxLength) {
        jsonResponse(['error' => 'One or more fields are too long.'], 422);
    }
    return $value === '' ? null : $value;
};
$dateTime = static function ($value): string {
    $value = trim((string) $value);
    if ($value === '') return date('Y-m-d H:i:s');
    $timestamp = strtotime(str_replace('T', ' ', $value));
    if ($timestamp === false) jsonResponse(['error' => 'Invalid distribution date and time.'], 422);
    return date('Y-m-d H:i:s', $timestamp);
};
$validStatus = ['Completed', 'Pending review'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resourceId = positiveInt($data, 'resource_id');
    $quantity = positiveInt($data, 'quantity');
    $recipientName = requiredString($data, 'recipient_name', 150);
    $status = in_array($data['status'] ?? 'Completed', $validStatus, true) ? $data['status'] : 'Completed';
    $conn->beginTransaction();
    try {
        $stock = $conn->prepare("UPDATE resources SET stock = stock - ? WHERE id = ? AND status <> 'Inactive' AND stock >= ?");
        $stock->execute([$quantity, $resourceId, $quantity]);
        if (!$stock->rowCount()) throw new RuntimeException('Resource not found or stock is insufficient.');
        $stmt = $conn->prepare(
            'INSERT INTO distributions
                (resource_id, recipient_reference, recipient_name, quantity, distributed_by, distributed_at, location, remarks, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $resourceId, $optional($data['recipient_reference'] ?? null, 80), $recipientName, $quantity,
            currentUserId(), $dateTime($data['distributed_at'] ?? null),
            $optional($data['location'] ?? null, 255), $optional($data['remarks'] ?? null, 10000), $status,
        ]);
        $id = (int) $conn->lastInsertId();
        logActivity($conn, 'create', 'distribution', $id, ['resource_id' => $resourceId, 'quantity' => $quantity]);
        $conn->commit();
        jsonResponse(['id' => $id, 'message' => 'Distribution recorded and stock deducted.'], 201);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        jsonResponse(['error' => $e->getMessage()], 422);
    }
}

$id = positiveInt($data, 'id');
$conn->beginTransaction();
try {
    $current = $conn->prepare('SELECT resource_id, quantity FROM distributions WHERE id = ? FOR UPDATE');
    $current->execute([$id]);
    $record = $current->fetch();
    if (!$record) throw new RuntimeException('Distribution not found.');

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $conn->prepare('UPDATE resources SET stock = stock + ? WHERE id = ?')->execute([(int) $record['quantity'], (int) $record['resource_id']]);
        $conn->prepare('DELETE FROM distributions WHERE id = ?')->execute([$id]);
        logActivity($conn, 'delete', 'distribution', $id, ['reason' => $optional($data['reason'] ?? null, 10000)]);
        $conn->commit();
        jsonResponse(['message' => 'Distribution reversed and stock restored.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        $conn->rollBack();
        methodNotAllowed(['GET', 'POST', 'PUT', 'DELETE']);
    }

    $resourceId = positiveInt($data, 'resource_id');
    $quantity = positiveInt($data, 'quantity');
    $recipientName = requiredString($data, 'recipient_name', 150);
    $status = in_array($data['status'] ?? 'Completed', $validStatus, true) ? $data['status'] : 'Completed';
    $oldResourceId = (int) $record['resource_id'];
    $oldQuantity = (int) $record['quantity'];

    if ($resourceId !== $oldResourceId) {
        $conn->prepare('UPDATE resources SET stock = stock + ? WHERE id = ?')->execute([$oldQuantity, $oldResourceId]);
        $stock = $conn->prepare("UPDATE resources SET stock = stock - ? WHERE id = ? AND status <> 'Inactive' AND stock >= ?");
        $stock->execute([$quantity, $resourceId, $quantity]);
        if (!$stock->rowCount()) throw new RuntimeException('New resource not found or stock is insufficient.');
    } else {
        $delta = $quantity - $oldQuantity;
        if ($delta > 0) {
            $stock = $conn->prepare("UPDATE resources SET stock = stock - ? WHERE id = ? AND status <> 'Inactive' AND stock >= ?");
            $stock->execute([$delta, $resourceId, $delta]);
            if (!$stock->rowCount()) throw new RuntimeException('Stock is insufficient for this adjustment.');
        } elseif ($delta < 0) {
            $conn->prepare('UPDATE resources SET stock = stock + ? WHERE id = ?')->execute([abs($delta), $resourceId]);
        }
    }

    $stmt = $conn->prepare(
        'UPDATE distributions SET resource_id = ?, recipient_reference = ?, recipient_name = ?, quantity = ?,
            distributed_at = ?, location = ?, remarks = ?, status = ? WHERE id = ?'
    );
    $stmt->execute([
        $resourceId, $optional($data['recipient_reference'] ?? null, 80), $recipientName, $quantity,
        $dateTime($data['distributed_at'] ?? null), $optional($data['location'] ?? null, 255),
        $optional($data['remarks'] ?? null, 10000), $status, $id,
    ]);
    logActivity($conn, 'update', 'distribution', $id);
    $conn->commit();
    jsonResponse(['message' => 'Distribution updated.']);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    jsonResponse(['error' => $e->getMessage()], 422);
}
