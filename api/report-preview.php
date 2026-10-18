<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') methodNotAllowed(['GET']);
$validDate = static function ($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed && $parsed->format('Y-m-d') === $value;
};
$from = $validDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = $validDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$where = " FROM resources WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?";
$params = [$from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')];
if (($coverage = adminQueryText('coverage', 120)) !== '') {
    $where .= ' AND category = ?';
    $params[] = $coverage;
}
if (($search = adminQueryText('q')) !== '') {
    $where .= " AND CONCAT_WS(' ', name, category, unit, notes, location) LIKE ? ESCAPE '!'";
    $params[] = adminLike($search);
}
$select = "SELECT id, name, category, stock, unit, low_stock_threshold,
    CASE WHEN stock = 0 THEN 'Out of stock' WHEN stock <= low_stock_threshold THEN 'Low stock' ELSE 'In stock' END AS stock_status" . $where . ' ORDER BY name, id';
if (($_GET['export'] ?? '') === 'print') {
    $statement = $conn->prepare($select);
    $statement->execute($params);
    adminSendPrint('Resource stock report', ['name' => 'Resource', 'category' => 'Category', 'stock' => 'Available', 'unit' => 'Unit', 'low_stock_threshold' => 'Threshold', 'stock_status' => 'Status'], $statement);
}
$result = adminPaginate($conn, $select, 'SELECT COUNT(*)' . $where, $params);
$summary = $conn->prepare('SELECT COUNT(DISTINCT category) AS categories' . $where);
$summary->execute($params);
$result['stats'] = $summary->fetch();
jsonResponse($result);
