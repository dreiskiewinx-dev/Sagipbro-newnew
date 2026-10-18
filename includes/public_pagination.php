<?php
declare(strict_types=1);

function publicPageRequest(array $query, string $pageKey = 'page', string $sizeKey = 'per_page'): array
{
    $page = filter_var($query[$pageKey] ?? 1, FILTER_VALIDATE_INT);
    $size = filter_var($query[$sizeKey] ?? 6, FILTER_VALIDATE_INT);
    return [
        'page' => $page !== false && $page > 0 ? $page : 1,
        'per_page' => in_array($size, [6, 10, 25, 50, 100], true) ? $size : 6,
    ];
}

/** Null options preserve full-data consumers such as exports and summary widgets. */
function publicPaginate(PDO $db, string $selectSql, string $countSql, array $params = [], ?array $paging = null): array
{
    if ($paging === null) {
        $statement = $db->prepare($selectSql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows, 'total' => count($rows)];
    }
    $options = publicPageRequest($paging);
    $count = $db->prepare($countSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $options['per_page']));
    $page = min($options['page'], $pages);
    $offset = ($page - 1) * $options['per_page'];

    $statement = $db->prepare(rtrim($selectSql, " \t\r\n;") . ' LIMIT ? OFFSET ?');
    $position = 1;
    foreach ($params as $value) $statement->bindValue($position++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $statement->bindValue($position++, $options['per_page'], PDO::PARAM_INT);
    $statement->bindValue($position, $offset, PDO::PARAM_INT);
    $statement->execute();
    return [
        'rows' => $statement->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'pagination' => [
            'page' => $page, 'per_page' => $options['per_page'], 'total' => $total,
            'total_pages' => $pages, 'from' => $total ? $offset + 1 : 0,
            'to' => min($offset + $options['per_page'], $total),
        ],
    ];
}

function publicPaginationUrl(string $action, array $query): string
{
    $parts = parse_url($action);
    $query = array_filter($query, static fn ($value) => is_scalar($value) && (string) $value !== '');
    return ($parts['path'] ?? '') . ($query ? '?' . http_build_query($query) : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}
