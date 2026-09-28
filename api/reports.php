<?php

require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
$conn = sagipbroDatabase();

$report = $_GET['report'] ?? 'summary';
$export = ($_GET['export'] ?? '') === 'csv';
$validDate = static function ($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = requestData();
    if (($data['action'] ?? '') !== 'record_export') jsonResponse(['error' => 'Unknown report action.'], 422);

    $allowedReports = ['overview', 'resources', 'distribution', 'evacuation', 'residents', 'activity', 'summary', 'custom'];
    $savedReport = is_string($data['report'] ?? null) ? (string) $data['report'] : '';
    if (!in_array($savedReport, $allowedReports, true)) jsonResponse(['error' => 'Invalid report type.'], 422);

    $savedTitle = trim(is_string($data['title'] ?? null) ? (string) $data['title'] : '');
    if ($savedTitle === '' || strlen($savedTitle) > 180) jsonResponse(['error' => 'Invalid report title.'], 422);
    if (!$validDate($data['from'] ?? null) || !$validDate($data['to'] ?? null)) jsonResponse(['error' => 'Invalid report period.'], 422);

    $savedFrom = (string) $data['from'];
    $savedTo = (string) $data['to'];
    if ($savedFrom > $savedTo) [$savedFrom, $savedTo] = [$savedTo, $savedFrom];
    $savedCoverage = is_string($data['coverage'] ?? null) ? trim((string) $data['coverage']) : '';
    if (strlen($savedCoverage) > 120) jsonResponse(['error' => 'Invalid report category.'], 422);
    $savedSections = is_array($data['sections'] ?? null)
        ? array_values(array_intersect(['resources', 'distributions', 'evacuation', 'people'], array_map('strval', $data['sections'])))
        : [];

    $details = [
        'report' => $savedReport,
        'title' => $savedTitle,
        'from' => $savedFrom,
        'to' => $savedTo,
        'coverage' => $savedCoverage,
        'format' => 'CSV',
    ];
    if ($savedReport === 'custom') $details['sections'] = $savedSections;
    logActivity($conn, 'generate', 'report', null, $details);
    jsonResponse(['message' => 'Saved report added to recent reports.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') methodNotAllowed(['GET', 'POST']);

$from = $validDate($_GET['from'] ?? null) ? (string) $_GET['from'] : date('Y-m-01');
$to = $validDate($_GET['to'] ?? null) ? (string) $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$coverage = is_string($_GET['coverage'] ?? null) ? trim((string) $_GET['coverage']) : '';
$periodStart = $from . ' 00:00:00';
$periodEnd = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');

$safeCsvCell = static function ($value): string {
    $value = (string) ($value ?? '');
    return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
};
$sendCsv = static function (string $filename, array $columns, array $rows) use ($safeCsvCell): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, max-age=0');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to prepare the report export.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values($columns), ',', '"', '');
    foreach ($rows as $row) {
        $values = [];
        foreach (array_keys($columns) as $column) {
            $values[] = $safeCsvCell($row[$column] ?? '');
        }
        fputcsv($output, $values, ',', '"', '');
    }
    fclose($output);
    exit;
};
$recordExport = static function (string $reportType, string $title, array $extra = []) use ($conn, $from, $to, $coverage): void {
    if (($_GET['track'] ?? '1') === '0') return;
    logActivity($conn, 'generate', 'report', null, array_merge([
        'report' => $reportType,
        'title' => $title,
        'from' => $from,
        'to' => $to,
        'coverage' => $coverage,
        'format' => 'CSV',
    ], $extra));
};

if ($report === 'custom') {
    $requestedSections = is_array($_GET['sections'] ?? null) ? $_GET['sections'] : [];
    $sections = array_values(array_intersect(['resources', 'distributions', 'evacuation', 'people'], array_map('strval', $requestedSections)));
    if (!$sections) jsonResponse(['error' => 'Select at least one report section.'], 422);
    $title = trim(is_string($_GET['title'] ?? null) ? (string) $_GET['title'] : 'Custom Operations Summary');
    if ($title === '' || strlen($title) > 180) jsonResponse(['error' => 'Invalid report title.'], 422);
    $rows = [];

    if (in_array('resources', $sections, true)) {
        $categorySql = $coverage !== '' ? ' AND category = ?' : '';
        $params = [$periodStart, $periodEnd];
        if ($coverage !== '') $params[] = $coverage;
        $statement = $conn->prepare("SELECT name, category, stock, unit FROM resources WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?{$categorySql} ORDER BY name");
        $statement->execute($params);
        foreach ($statement->fetchAll() as $item) {
            $rows[] = ['section' => 'Resource inventory', 'item' => $item['name'], 'value' => $item['stock'] . ' ' . $item['unit'], 'details' => $item['category']];
        }
    }
    if (in_array('distributions', $sections, true)) {
        $categorySql = $coverage !== '' ? ' AND r.category = ?' : '';
        $params = [$periodStart, $periodEnd];
        if ($coverage !== '') $params[] = $coverage;
        $statement = $conn->prepare("SELECT r.name, SUM(d.quantity) AS quantity, COUNT(*) AS transactions FROM distributions d JOIN resources r ON r.id = d.resource_id WHERE d.distributed_at >= ? AND d.distributed_at < ?{$categorySql} GROUP BY d.resource_id, r.name ORDER BY r.name");
        $statement->execute($params);
        foreach ($statement->fetchAll() as $item) {
            $rows[] = ['section' => 'Relief distributions', 'item' => $item['name'], 'value' => $item['quantity'], 'details' => $item['transactions'] . ' transactions'];
        }
    }
    if (in_array('evacuation', $sections, true)) {
        $statement = $conn->prepare('SELECT name, capacity, occupants, status FROM evacuation_centers WHERE updated_at >= ? AND updated_at < ? ORDER BY name');
        $statement->execute([$periodStart, $periodEnd]);
        foreach ($statement->fetchAll() as $item) {
            $rows[] = ['section' => 'Evacuation centers', 'item' => $item['name'], 'value' => $item['occupants'] . ' / ' . $item['capacity'], 'details' => $item['status']];
        }
    }
    if (in_array('people', $sections, true)) {
        $statement = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(sex = 'Male'), 0) AS male, COALESCE(SUM(sex = 'Female'), 0) AS female FROM residents WHERE status = 'Active' AND updated_at >= ? AND updated_at < ?");
        $statement->execute([$periodStart, $periodEnd]);
        $item = $statement->fetch() ?: ['total' => 0, 'male' => 0, 'female' => 0];
        $rows[] = ['section' => 'Residents', 'item' => 'Active residents', 'value' => $item['total'], 'details' => $item['male'] . ' male; ' . $item['female'] . ' female'];
    }

    if ($export) {
        $recordExport('custom', $title, ['sections' => $sections]);
        $sendCsv('sagipbro-custom-report-' . date('Y-m-d') . '.csv', [
            'section' => 'Section', 'item' => 'Item', 'value' => 'Value', 'details' => 'Details',
        ], $rows);
    }
    jsonResponse(['report' => 'custom', 'title' => $title, 'data' => $rows]);
}

if ($report === 'overview') {
    $resourceCategorySql = $coverage !== '' ? ' AND category = ?' : '';
    $resourceParams = [$periodStart, $periodEnd];
    if ($coverage !== '') $resourceParams[] = $coverage;
    $resourceStats = $conn->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(stock), 0) AS resource_units,
        COALESCE(SUM(stock <= low_stock_threshold), 0) AS low_stock FROM resources
        WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?{$resourceCategorySql}");
    $resourceStats->execute($resourceParams);
    $stats = $resourceStats->fetch() ?: [];
    $distributionCategorySql = $coverage !== '' ? ' AND r.category = ?' : '';
    $distributionParams = [$periodStart, $periodEnd];
    if ($coverage !== '') $distributionParams[] = $coverage;
    $distributionCount = $conn->prepare("SELECT COUNT(*) FROM distributions d JOIN resources r ON r.id = d.resource_id
        WHERE d.distributed_at >= ? AND d.distributed_at < ?{$distributionCategorySql}");
    $distributionCount->execute($distributionParams);
    $centerCount = $conn->prepare('SELECT COUNT(*) FROM evacuation_centers WHERE updated_at >= ? AND updated_at < ?');
    $centerCount->execute([$periodStart, $periodEnd]);
    $residentCount = $conn->prepare("SELECT COUNT(*) FROM residents WHERE status = 'Active' AND updated_at >= ? AND updated_at < ?");
    $residentCount->execute([$periodStart, $periodEnd]);
    $stats['records'] = (int) ($stats['records'] ?? 0) + (int) $distributionCount->fetchColumn() + (int) $centerCount->fetchColumn() + (int) $residentCount->fetchColumn();
    $rows = [
        ['item' => 'Reporting period', 'value' => "{$from} to {$to}", 'description' => 'Selected overview period'],
        ['item' => 'Resource category', 'value' => $coverage ?: 'All categories', 'description' => 'Selected resource coverage'],
        ['item' => 'Reports available', 'value' => 4, 'description' => 'Live operational report types'],
        ['item' => 'Records summarized', 'value' => $stats['records'] ?? 0, 'description' => 'Current database records'],
        ['item' => 'Available resource units', 'value' => $stats['resource_units'] ?? 0, 'description' => 'Inventory updated during the selected period'],
        ['item' => 'Low-stock resources', 'value' => $stats['low_stock'] ?? 0, 'description' => 'Items at or below their threshold'],
        ['item' => 'Resource stock', 'value' => 'Resources', 'description' => 'Current inventory quantities and low-stock conditions'],
        ['item' => 'Distribution summary', 'value' => 'Distributions', 'description' => 'Relief releases grouped by resource'],
        ['item' => 'Evacuation capacity', 'value' => 'Centers', 'description' => 'Center occupancy, capacity, and available spaces'],
        ['item' => 'Resident summary', 'value' => 'Residents', 'description' => 'Active resident totals'],
        ['item' => 'Generated at', 'value' => date('Y-m-d H:i:s'), 'description' => 'Philippine Standard Time'],
    ];
    if ($export) {
        $recordExport('overview', 'Reports overview');
        $sendCsv('sagipbro-report-overview-' . date('Y-m-d') . '.csv', [
            'item' => 'Overview item', 'value' => 'Value', 'description' => 'Description',
        ], $rows);
    }
    jsonResponse(['report' => 'overview', 'data' => $rows]);
}

$columns = [];
switch ($report) {
    case 'resources':
        $categorySql = $coverage !== '' ? ' AND category = ?' : '';
        $params = [$periodStart, $periodEnd];
        if ($coverage !== '') $params[] = $coverage;
        $stmt = $conn->prepare("SELECT name, category, stock, unit, CASE WHEN stock = 0 THEN 'Out of stock' WHEN stock <= low_stock_threshold THEN 'Low stock' ELSE 'In stock' END AS stock_status FROM resources WHERE status <> 'Inactive' AND updated_at >= ? AND updated_at < ?{$categorySql} ORDER BY stock");
        $stmt->execute($params);
        $columns = ['name' => 'Resource', 'category' => 'Category', 'stock' => 'Available quantity', 'unit' => 'Unit', 'stock_status' => 'Stock status'];
        break;
    case 'residents':
        $stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(sex = 'Male'), 0) AS male, COALESCE(SUM(sex = 'Female'), 0) AS female FROM residents WHERE status = 'Active' AND updated_at >= ? AND updated_at < ?");
        $stmt->execute([$periodStart, $periodEnd]);
        $columns = ['total' => 'Active residents', 'male' => 'Male', 'female' => 'Female'];
        break;
    case 'evacuation':
        $stmt = $conn->prepare('SELECT name, capacity, occupants, GREATEST(capacity - occupants, 0) AS available_capacity, status FROM evacuation_centers WHERE updated_at >= ? AND updated_at < ? ORDER BY name');
        $stmt->execute([$periodStart, $periodEnd]);
        $columns = ['name' => 'Evacuation center', 'capacity' => 'Capacity', 'occupants' => 'Occupants', 'available_capacity' => 'Available spaces', 'status' => 'Status'];
        break;
    case 'distribution':
        $categorySql = $coverage !== '' ? ' AND r.category = ?' : '';
        $params = [$periodStart, $periodEnd];
        if ($coverage !== '') $params[] = $coverage;
        $stmt = $conn->prepare("SELECT r.name AS resource, SUM(d.quantity) AS quantity_distributed, COUNT(*) AS transactions FROM distributions d JOIN resources r ON r.id = d.resource_id WHERE d.distributed_at >= ? AND d.distributed_at < ?{$categorySql} GROUP BY d.resource_id, r.name ORDER BY quantity_distributed DESC");
        $stmt->execute($params);
        $columns = ['resource' => 'Resource', 'quantity_distributed' => 'Quantity distributed', 'transactions' => 'Transactions'];
        break;
    case 'activity':
        $stmt = $conn->query('SELECT l.*, u.full_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 500');
        break;
    case 'summary':
        $stmt = $conn->query("SELECT
            (SELECT COUNT(*) FROM residents WHERE status = 'Active') AS residents,
            (SELECT COUNT(*) FROM resources WHERE status <> 'Inactive') AS resources,
            (SELECT COUNT(*) FROM resources WHERE status <> 'Inactive' AND stock <= low_stock_threshold) AS low_stock,
            (SELECT COUNT(*) FROM evacuation_centers) AS centers,
            (SELECT COUNT(*) FROM evacuation_centers WHERE status = 'Open') AS open_centers,
            (SELECT COUNT(*) FROM volunteers WHERE status IN ('Active', 'Deployed')) AS volunteers,
            (SELECT COUNT(*) FROM distributions WHERE distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS distributions,
            (SELECT COALESCE(SUM(quantity), 0) FROM distributions WHERE distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS distributed_quantity,
            (SELECT COUNT(*) FROM evacuees WHERE checked_out_at IS NULL) AS active_evacuees");
        break;
    default:
        jsonResponse(['error' => 'Unknown report.'], 404);
}
/** @var PDOStatement $stmt */
$rows = $stmt->fetchAll();
if ($export) {
    if (!$columns) {
        foreach (array_keys($rows[0] ?? []) as $column) {
            $columns[$column] = ucwords(str_replace('_', ' ', $column));
        }
    }
    $titles = [
        'resources' => 'Resource stock',
        'distribution' => 'Distribution summary',
        'evacuation' => 'Evacuation capacity',
        'residents' => 'Resident summary',
        'activity' => 'Activity log',
        'summary' => 'Operations summary',
    ];
    $recordExport((string) $report, $titles[$report] ?? ucfirst((string) $report) . ' report');
    $sendCsv('sagipbro-' . $report . '-report-' . date('Y-m-d') . '.csv', $columns, $rows);
}
jsonResponse(['report' => $report, 'data' => $rows]);
