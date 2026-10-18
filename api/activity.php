<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') methodNotAllowed(['GET']);

$from = ' FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id';
$select = "SELECT l.*, COALESCE(u.full_name, u.username, 'System') AS actor";
$where = [];
$params = [];
$search = adminQueryText('q');
if ($search !== '') {
    $fields = ["CONCAT('LOG-', LPAD(l.id, 5, '0'))", 'l.id', 'l.action', 'l.entity_type', 'l.entity_id', 'l.details', 'l.ip_address', 'u.full_name', 'u.username'];
    $where[] = '(' . implode(' OR ', array_map(static fn ($field) => "$field LIKE ? ESCAPE '!'", $fields)) . ')';
    foreach ($fields as $field) $params[] = adminLike($search);
}
$action = adminQueryText('action');
$actionMap = ['Created' => 'create', 'Updated' => 'update', 'Published' => 'publish', 'Signed in' => 'login', 'Exported' => 'generate'];
if (isset($actionMap[$action])) {
    $where[] = 'l.action = ?';
    $params[] = $actionMap[$action];
} elseif ($action === 'Warning') {
    $where[] = "(LOWER(l.action) LIKE '%fail%' OR LOWER(l.action) LIKE '%warning%')";
} elseif ($action === 'Blocked') {
    $where[] = "LOWER(l.action) LIKE '%block%'";
} elseif ($action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
$condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$order = ' ORDER BY l.created_at DESC, l.id DESC';
$format = static function (array $row): array {
    $labels = ['create' => 'Created', 'update' => 'Updated', 'delete' => 'Deleted', 'publish' => 'Published', 'login' => 'Signed in', 'generate' => 'Exported'];
    $actor = (string) $row['actor'];
    $words = preg_split('/\s+/', trim($actor));
    $details = trim((string) ($row['details'] ?? ''));
    return [
        'id' => (int) $row['id'],
        'reference' => 'LOG-' . str_pad((string) $row['id'], 5, '0', STR_PAD_LEFT),
        'event' => $labels[$row['action']] ?? ucfirst(str_replace('-', ' ', (string) $row['action'])),
        'user' => $actor,
        'initials' => strtoupper(substr($words[0] ?? 'S', 0, 1) . substr($words[1] ?? '', 0, 1)),
        'module' => ucwords(str_replace(['_', '-'], ' ', (string) $row['entity_type'])),
        'details' => $details !== '' ? $details : ($row['entity_id'] ? 'Record #' . $row['entity_id'] : 'No additional details'),
        'source' => (string) ($row['ip_address'] ?: 'Local'),
        'date' => date('M j, Y', strtotime((string) $row['created_at'])),
        'time' => date('g:i:s A', strtotime((string) $row['created_at'])),
        'tone' => str_contains(strtolower($row['action']), 'block') ? 'danger' : (preg_match('/fail|warning/i', $row['action']) ? 'warning' : 'success'),
    ];
};

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id !== false && $id !== null) {
    $target = $conn->prepare($select . $from . ' WHERE l.id = ?');
    $target->execute([$id]);
    $row = $target->fetch();
    if (!$row) jsonResponse(['error' => 'Activity event not found.'], 404);
    $rank = $conn->prepare('SELECT COUNT(*) FROM activity_logs WHERE created_at > ? OR (created_at = ? AND id > ?)');
    $rank->execute([$row['created_at'], $row['created_at'], $id]);
    $perPage = filter_var($_GET['per_page'] ?? 10, FILTER_VALIDATE_INT);
    if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 10;
    jsonResponse(['data' => [$format($row)], 'record_page' => (int) floor((int) $rank->fetchColumn() / $perPage) + 1]);
}
if (in_array($_GET['export'] ?? '', ['csv', 'print'], true)) {
    $statement = $conn->prepare($select . $from . $condition . $order);
    $statement->execute($params);
    $columns = ['reference' => 'Reference', 'event' => 'Event', 'user' => 'User', 'module' => 'Module', 'details' => 'Details', 'source' => 'Source', 'date' => 'Date', 'time' => 'Time'];
    $rows = array_map($format, $statement->fetchAll());
    if ($_GET['export'] === 'print') adminSendPrint('Activity log', $columns, $rows);
    adminSendCsv('sagipbro-activity-log.csv', $columns, $rows);
}
$result = adminPaginate($conn, $select . $from . $condition . $order, 'SELECT COUNT(*)' . $from . $condition, $params);
$result['data'] = array_map($format, $result['data']);
jsonResponse($result);
