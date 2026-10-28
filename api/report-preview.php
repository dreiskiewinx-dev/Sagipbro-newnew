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
$report = adminQueryText('report', 30) ?: 'resources';
if (!in_array($report, ['resources', 'distribution', 'evacuation', 'residents'], true)) jsonResponse(['error' => 'Choose a valid report preview.'], 422);
$params = [$from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00')];
$coverage = adminQueryText('coverage', 120);
$search = adminQueryText('q');
switch ($report) {
    case 'resources':
        $title = 'Resource stock report';
        $where = " FROM resources WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?";
        if ($coverage !== '') { $where .= ' AND category = ?'; $params[] = $coverage; }
        if ($search !== '') { $where .= " AND CONCAT_WS(' ', name, category, unit, notes, location) LIKE ? ESCAPE '!'"; $params[] = adminLike($search); }
        $select = "SELECT id, name, category, stock, unit, low_stock_threshold,
            CASE WHEN stock = 0 THEN 'Out of stock' WHEN stock <= low_stock_threshold THEN 'Low stock' ELSE 'In stock' END AS stock_status" . $where . ' ORDER BY name, id';
        $countSql = 'SELECT COUNT(*)' . $where;
        $summarySql = 'SELECT COUNT(DISTINCT category) AS categories, COALESCE(SUM(stock), 0) AS units, COALESCE(SUM(stock <= low_stock_threshold), 0) AS low_stock' . $where;
        $columns = ['name' => 'Resource', 'category' => 'Category', 'stock' => 'Available', 'unit' => 'Unit', 'low_stock_threshold' => 'Threshold', 'stock_status' => 'Status'];
        $metricFields = ['units' => 'Total available units', 'categories' => 'Resource categories', 'low_stock' => 'Low-stock items'];
        break;
    case 'distribution':
        $title = 'Distribution summary';
        $where = ' FROM distributions d JOIN resources r ON r.id = d.resource_id WHERE d.distributed_at >= ? AND d.distributed_at < ?';
        if ($coverage !== '') { $where .= ' AND r.category = ?'; $params[] = $coverage; }
        if ($search !== '') { $where .= " AND CONCAT_WS(' ', r.name, r.category) LIKE ? ESCAPE '!'"; $params[] = adminLike($search); }
        $grouped = 'SELECT d.resource_id AS id, r.name AS resource, r.unit, SUM(d.quantity) AS quantity_distributed, COUNT(*) AS transactions' . $where . ' GROUP BY d.resource_id, r.name, r.unit';
        $select = $grouped . ' ORDER BY quantity_distributed DESC, d.resource_id';
        $countSql = 'SELECT COUNT(*) FROM (' . $grouped . ') distribution_summary';
        $summarySql = "SELECT COUNT(*) AS transactions, COUNT(DISTINCT d.resource_id) AS resources, COUNT(DISTINCT NULLIF(TRIM(d.location), '')) AS locations" . $where;
        $columns = ['resource' => 'Resource', 'quantity_distributed' => 'Quantity distributed', 'unit' => 'Unit', 'transactions' => 'Transactions'];
        $metricFields = ['transactions' => 'Distribution transactions', 'resources' => 'Resource types', 'locations' => 'Recorded locations'];
        break;
    case 'evacuation':
        $title = 'Evacuation capacity';
        $where = ' FROM evacuation_centers WHERE updated_at >= ? AND updated_at < ?';
        if ($search !== '') { $where .= " AND CONCAT_WS(' ', name, address, status) LIKE ? ESCAPE '!'"; $params[] = adminLike($search); }
        $available = 'GREATEST(CAST(capacity AS SIGNED) - CAST(occupants AS SIGNED), 0)';
        $select = 'SELECT id, name, capacity, occupants, ' . $available . ' AS available_capacity, status' . $where . ' ORDER BY name, id';
        $countSql = 'SELECT COUNT(*)' . $where;
        $summarySql = 'SELECT COALESCE(SUM(capacity), 0) AS capacity, COALESCE(SUM(occupants), 0) AS occupants, COALESCE(SUM(' . $available . '), 0) AS available_capacity' . $where;
        $columns = ['name' => 'Evacuation center', 'capacity' => 'Capacity', 'occupants' => 'Occupants', 'available_capacity' => 'Available spaces', 'status' => 'Status'];
        $metricFields = ['capacity' => 'Total capacity', 'occupants' => 'Current occupants', 'available_capacity' => 'Available spaces'];
        break;
    case 'residents':
        $title = 'Resident summary';
        $where = " FROM residents WHERE status = 'Active' AND updated_at >= ? AND updated_at < ?";
        if ($search !== '') { $where .= " AND CONCAT_WS(' ', first_name, last_name, vulnerability) LIKE ? ESCAPE '!'"; $params[] = adminLike($search); }
        $summarySql = "SELECT COUNT(*) AS total, COALESCE(SUM(sex = 'Male'), 0) AS male, COALESCE(SUM(sex = 'Female'), 0) AS female,
            COALESCE(SUM(sex = 'Other'), 0) AS other,
            COALESCE(SUM(LOWER(TRIM(COALESCE(vulnerability, ''))) NOT IN ('', 'none', 'not recorded')), 0) AS vulnerable,
            COUNT(DISTINCT household_id) AS households" . $where;
        $select = $summarySql;
        $countSql = 'SELECT COUNT(*) FROM (' . $summarySql . ') resident_summary';
        $columns = ['total' => 'Active residents', 'male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'vulnerable' => 'With recorded vulnerability'];
        $metricFields = ['total' => 'Active residents', 'vulnerable' => 'With recorded vulnerability', 'households' => 'Households represented'];
        break;
}
if (($_GET['export'] ?? '') === 'print') {
    $statement = $conn->prepare($select);
    $statement->execute($params);
    adminSendPrint($title, $columns, $statement);
}
$result = adminPaginate($conn, $select, $countSql, $params);
$summary = $conn->prepare($summarySql);
$summary->execute($params);
$result['stats'] = $summary->fetch();
$result['report'] = $report;
$result['columns'] = $columns;
$result['metrics'] = [];
foreach ($metricFields as $field => $label) $result['metrics'][] = ['label' => $label, 'value' => (int) $result['stats'][$field]];
jsonResponse($result);
