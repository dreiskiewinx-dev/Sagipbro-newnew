<?php
declare(strict_types=1);

/** Opt-in keeps existing compact lookups and public API consumers compatible. */
function adminPaginationRequested(): bool
{
    return isset($_GET['page']) || isset($_GET['per_page']) || ($_GET['paginate'] ?? '') === '1';
}

function adminQueryText(string $key, int $max = 200): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? substr(trim($value), 0, $max) : '';
}

/** Use with a bound placeholder and SQL LIKE ... ESCAPE '!'. */
function adminLike(string $value): string
{
    return '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/** SQL and ordering are supplied by the endpoint; values are always bound. */
function adminPaginate(PDO $db, string $selectSql, string $countSql, array $params = []): array
{
    $perPage = 10;
    $requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $requestedPage = $requestedPage !== false && $requestedPage > 0 ? $requestedPage : 1;

    $count = $db->prepare($countSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $perPage;

    $statement = $db->prepare(rtrim($selectSql, " \t\r\n;") . ' LIMIT ? OFFSET ?');
    $position = 1;
    foreach ($params as $value) {
        $statement->bindValue($position++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue($position++, $perPage, PDO::PARAM_INT);
    $statement->bindValue($position, $offset, PDO::PARAM_INT);
    $statement->execute();

    return [
        'data' => $statement->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $total ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ],
    ];
}

/** Exports use the same filtered query, without the current page's limit. */
function adminSendCsv(string $filename, array $columns, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '-', $filename) . '"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values($columns), ',', '"', '');
    foreach ($rows as $row) {
        $cells = [];
        foreach (array_keys($columns) as $key) {
            $value = (string) ($row[$key] ?? '');
            $cells[] = preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
        }
        fputcsv($output, $cells, ',', '"', '');
    }
    fclose($output);
    exit;
}

/** Print every filtered result, rather than only the visible screen page. */
function adminSendPrint(string $title, array $columns, iterable $rows): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $escape = static fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $escape($title) . ' | SAGIPBRO</title><style>body{font:14px system-ui,sans-serif;color:#193c2e;margin:24px}h1{font-size:24px}table{width:100%;border-collapse:collapse}th,td{padding:10px;text-align:left;border-bottom:1px solid #dbe6df;overflow-wrap:anywhere}th{background:#e8f4ed;color:#0b5d3b}thead{display:table-header-group}tr{break-inside:avoid}@media print{body{margin:0}button{display:none}}</style></head><body><h1>SAGIPBRO — ' . $escape($title) . '</h1><p>Generated ' . $escape(date('M j, Y g:i A')) . ' (Philippine time)</p><button type="button" onclick="window.print()">Print</button><table><thead><tr>';
    foreach ($columns as $label) echo '<th scope="col">' . $escape($label) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($columns) as $key) echo '<td>' . $escape($row[$key] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table><script>window.addEventListener("load",()=>window.print());</script></body></html>';
    exit;
}
