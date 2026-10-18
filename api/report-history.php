<?php
require_once __DIR__ . '/bootstrap.php';
requireApiLogin(['admin', 'official']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') methodNotAllowed(['GET']);

$allowed = "'overview','resources','distribution','evacuation','residents','activity','summary','custom'";
$from = " FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
    WHERE l.action = 'generate' AND l.entity_type = 'report'
    AND JSON_VALID(l.details) AND JSON_UNQUOTE(JSON_EXTRACT(l.details, '$.report')) IN ($allowed)";
$params = [];
if (($search = adminQueryText('q')) !== '') {
    $from .= " AND CONCAT_WS(' ', l.details, u.full_name, l.created_at) LIKE ? ESCAPE '!'";
    $params[] = adminLike($search);
}
$select = "SELECT l.id, l.details, l.created_at, COALESCE(u.full_name, 'System user') AS generated_by" . $from . ' ORDER BY l.created_at DESC, l.id DESC';
$result = adminPaginate($conn, $select, 'SELECT COUNT(*)' . $from, $params);
$result['data'] = array_map(static function (array $entry): array {
    $details = json_decode((string) $entry['details'], true);
    $validDate = static fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        && ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) && $parsed->format('Y-m-d') === $date;
    $from = $validDate($details['from'] ?? null) ? $details['from'] : date('Y-m-01');
    $to = $validDate($details['to'] ?? null) ? $details['to'] : date('Y-m-d');
    $query = [
        'report' => $details['report'], 'export' => 'csv', 'from' => $from, 'to' => $to,
        'coverage' => is_string($details['coverage'] ?? null) ? $details['coverage'] : '',
        'sections' => is_array($details['sections'] ?? null) ? array_values(array_intersect(['resources', 'distributions', 'evacuation', 'people'], $details['sections'])) : [],
        'title' => is_string($details['title'] ?? null) ? $details['title'] : 'SAGIPBRO report', 'track' => '0',
    ];
    return [
        'id' => (int) $entry['id'], 'title' => $query['title'], 'coverage' => $query['coverage'],
        'from' => $from, 'to' => $to,
        'format' => is_string($details['format'] ?? null) ? $details['format'] : 'CSV',
        'generated_by' => $entry['generated_by'], 'created_at' => $entry['created_at'],
        'download_url' => appUrl('api/reports.php?' . http_build_query($query)),
    ];
}, $result['data']);
jsonResponse($result);
