<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/connection.php';
require_once __DIR__ . '/public_pagination.php';

/** Only known schema identifiers are interpolated. Public requests never supply SQL identifiers. */
function publicSchema(PDO $db): array
{
    static $schemas;
    $schemas ??= new WeakMap();
    if (isset($schemas[$db])) {
        return $schemas[$db];
    }
    $columns = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('resources','evacuation_centers','distributions','announcements')")->fetchAll();
    $tables = [];
    foreach ($columns as $column) {
        $tables[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
    }
    foreach (['resources', 'evacuation_centers', 'distributions', 'announcements'] as $table) {
        if (empty($tables[$table])) {
            throw new RuntimeException('The public service database schema is incomplete.');
        }
    }
    $legacy = in_array('resource_name', $tables['resources'], true);
    $schemas[$db] = [
        'resource_name' => $legacy ? 'resource_name' : 'name',
        'stock' => $legacy ? 'quantity' : 'stock',
        'threshold' => $legacy ? 'minimum_stock' : 'low_stock_threshold',
        'resource_visible' => in_array('status', $tables['resources'], true) ? "r.status = 'Available'" : '1 = 1',
        'center_name' => in_array('center_name', $tables['evacuation_centers'], true) ? 'center_name' : 'name',
        'center_address' => in_array('location', $tables['evacuation_centers'], true) ? 'location' : 'address',
        'occupants' => in_array('current_occupants', $tables['evacuation_centers'], true) ? 'current_occupants' : 'occupants',
        'body' => in_array('content', $tables['announcements'], true) ? 'content' : (in_array('message', $tables['announcements'], true) ? 'message' : 'body'),
        'announcement_published_at' => in_array('published_at', $tables['announcements'], true) ? 'published_at' : 'created_at',
        'announcement_expires_at' => in_array('expires_at', $tables['announcements'], true) ? 'expires_at' : null,
        'distribution_date_only' => in_array('distribution_date', $tables['distributions'], true),
        'distribution_date' => in_array('distribution_date', $tables['distributions'], true) ? 'distribution_date' : 'distributed_at',
        'distribution_location' => in_array('distribution_location', $tables['distributions'], true) ? 'distribution_location' : null,
    ];
    return $schemas[$db];
}

function publicSearchTerm(array $filters): string
{
    return is_string($filters['q'] ?? null) ? trim(substr($filters['q'], 0, 200)) : '';
}

function publicLike(string $term): string
{
    return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

function publicResources(PDO $db, array $filters = [], ?array $paging = null): array
{
    $s = publicSchema($db);
    $sql = "SELECT r.id, r.{$s['resource_name']} AS name, r.category, r.unit,
        r.{$s['stock']} AS stock, r.{$s['threshold']} AS low_stock_threshold, r.updated_at,
        CASE WHEN r.{$s['stock']} <= 0 THEN 'Out of Stock'
             WHEN r.{$s['stock']} <= r.{$s['threshold']} THEN 'Low Stock' ELSE 'Available' END AS availability
        FROM resources r WHERE {$s['resource_visible']}";
    $where = [];
    $params = [];
    if (($term = publicSearchTerm($filters)) !== '') {
        $where[] = "(name LIKE ? ESCAPE '!' OR category LIKE ? ESCAPE '!')";
        $params[] = publicLike($term);
        $params[] = publicLike($term);
    }
    if (is_string($filters['category'] ?? null) && $filters['category'] !== '') {
        $where[] = 'category = ?';
        $params[] = substr($filters['category'], 0, 100);
    }
    if (in_array($filters['status'] ?? '', ['Available', 'Low Stock', 'Out of Stock'], true)) {
        $where[] = 'availability = ?';
        $params[] = $filters['status'];
    }
    $from = ' FROM (' . $sql . ') inventory' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $result = publicPaginate($db, 'SELECT *' . $from . ' ORDER BY name, id', 'SELECT COUNT(*)' . $from, $params, $paging);
    $categories = $db->query("SELECT DISTINCT r.category FROM resources r WHERE {$s['resource_visible']} ORDER BY r.category")->fetchAll(PDO::FETCH_COLUMN);
    $result['categories'] = $categories;
    return $result;
}

function publicCenters(PDO $db, array $filters = [], ?array $paging = null): array
{
    $s = publicSchema($db);
    $sql = "SELECT e.id, e.{$s['center_name']} AS name, e.{$s['center_address']} AS address,
        e.capacity, e.{$s['occupants']} AS occupants, e.updated_at,
        CASE WHEN LOWER(e.status) IN ('closed', 'under_maintenance') THEN 0
             ELSE GREATEST(CAST(e.capacity AS SIGNED) - CAST(e.{$s['occupants']} AS SIGNED), 0) END AS available_spaces,
        CASE WHEN LOWER(e.status) IN ('closed', 'under_maintenance') THEN 'Closed'
             WHEN LOWER(e.status) = 'full' OR e.{$s['occupants']} >= e.capacity THEN 'Full' ELSE 'Available' END AS availability
        FROM evacuation_centers e";
    $where = [];
    $params = [];
    if (($term = publicSearchTerm($filters)) !== '') {
        $where[] = "(name LIKE ? ESCAPE '!' OR address LIKE ? ESCAPE '!')";
        $params[] = publicLike($term);
        $params[] = publicLike($term);
    }
    if (in_array($filters['status'] ?? '', ['Available', 'Full', 'Closed'], true)) {
        $where[] = 'availability = ?';
        $params[] = $filters['status'];
    }
    $from = ' FROM (' . $sql . ') centers' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $result = publicPaginate($db, 'SELECT *' . $from . " ORDER BY FIELD(availability, 'Available','Full','Closed'), name, id", 'SELECT COUNT(*)' . $from, $params, $paging);
    foreach ($result['rows'] as &$row) {
        foreach (['capacity', 'occupants', 'available_spaces'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        if ($row['availability'] !== 'Available') {
            $row['available_spaces'] = 0;
        }
    }
    unset($row);
    return $result;
}

function publicAnnouncementVisibility(array $schema): array
{
    $publishedAt = $schema['announcement_published_at'];
    $where = ["LOWER(status) = 'published'", "{$publishedAt} IS NOT NULL", "{$publishedAt} <= NOW()"];
    if ($schema['announcement_expires_at'] !== null) {
        $expiresAt = $schema['announcement_expires_at'];
        $where[] = "({$expiresAt} IS NULL OR {$expiresAt} > NOW())";
    }
    return $where;
}

function publicAnnouncements(PDO $db, array $filters = [], ?array $paging = null): array
{
    $s = publicSchema($db);
    $publishedAt = $s['announcement_published_at'];
    $expiresAt = $s['announcement_expires_at'];
    $where = publicAnnouncementVisibility($s);
    $params = [];
    if (($term = publicSearchTerm($filters)) !== '') {
        $where[] = "(title LIKE ? ESCAPE '!' OR {$s['body']} LIKE ? ESCAPE '!')";
        $params[] = publicLike($term);
        $params[] = publicLike($term);
    }
    if (in_array($filters['priority'] ?? '', ['Normal', 'Urgent', 'High', 'Medium', 'Low'], true)) {
        $where[] = 'priority = ?';
        $params[] = $filters['priority'];
    }
    if (array_key_exists('id', $filters)) {
        // An explicit ID must never fall back to displaying unrelated public records.
        $id = filter_var($filters['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $where[] = 'id = ?';
        $params[] = $id === false ? 0 : $id;
    }
    $from = ' FROM announcements WHERE ' . implode(' AND ', $where);
    $select = "SELECT id, title, {$s['body']} AS body, priority, {$publishedAt} AS published_at, " . ($expiresAt !== null ? $expiresAt : 'NULL') . ' AS expires_at, updated_at';
    return publicPaginate($db, $select . $from . " ORDER BY (priority = 'Urgent') DESC, published_at DESC, id DESC", 'SELECT COUNT(*)' . $from, $params, $paging);
}

/** The floating shortcut shows the newest current publication, regardless of priority. */
function publicFloatingAnnouncement(PDO $db): ?array
{
    $schema = publicSchema($db);
    $where = implode(' AND ', publicAnnouncementVisibility($schema));
    $record = $db->query('SELECT id, title FROM announcements WHERE ' . $where
        . " ORDER BY {$schema['announcement_published_at']} DESC, id DESC LIMIT 1")->fetch();
    return $record ?: null;
}

/** Compact public announcement components share the same visibility and ordering rules. */
function publicLatestAnnouncements(PDO $db, int $limit): array
{
    if ($limit < 1 || $limit > 5) {
        throw new InvalidArgumentException('Compact announcement lists support one to five records.');
    }
    $schema = publicSchema($db);
    $where = ' FROM announcements WHERE ' . implode(' AND ', publicAnnouncementVisibility($schema));
    $expiresAt = $schema['announcement_expires_at'] ?? 'NULL';
    $statement = $db->prepare("SELECT id, title, {$schema['body']} AS body, priority,
        {$schema['announcement_published_at']} AS published_at, {$expiresAt} AS expires_at,
        'Published' AS status" . $where . " ORDER BY (priority = 'Urgent') DESC, published_at DESC, id DESC LIMIT ?");
    $statement->bindValue(1, $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function publicHomepageAnnouncements(PDO $db): array
{
    $schema = publicSchema($db);
    $where = ' FROM announcements WHERE ' . implode(' AND ', publicAnnouncementVisibility($schema));
    $expiresAt = $schema['announcement_expires_at'] ?? 'NULL';
    $select = "SELECT id, title, {$schema['body']} AS body, priority,
        {$schema['announcement_published_at']} AS published_at, {$expiresAt} AS expires_at,
        'Published' AS status";
    // The featured position always promotes the highest active priority; ties are newest first.
    $featured = $db->query($select . $where . " ORDER BY CASE LOWER(priority)
        WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2
        WHEN 'low' THEN 3 WHEN 'normal' THEN 4 ELSE 5 END,
        published_at DESC, id DESC LIMIT 1")->fetch();
    if (!$featured) {
        return ['rows' => [], 'urgent' => null];
    }
    // Keep the remaining urgent notices visible without repeating the featured announcement.
    $statement = $db->prepare($select . $where . " AND id <> ?
        ORDER BY (priority = 'Urgent') DESC, published_at DESC, id DESC LIMIT 3");
    $statement->bindValue(1, (int) $featured['id'], PDO::PARAM_INT);
    $statement->execute();
    $rows = array_merge([$featured], $statement->fetchAll());
    $urgent = strcasecmp((string) $featured['priority'], 'Urgent') === 0 ? $featured : null;
    return ['rows' => $rows, 'urgent' => $urgent];
}

function publicAnnouncementPanel(PDO $db): array
{
    $rows = publicLatestAnnouncements($db, 5);
    $schema = publicSchema($db);
    $where = ' FROM announcements WHERE ' . implode(' AND ', publicAnnouncementVisibility($schema));
    $urgent = (int) $db->query("SELECT COUNT(*)" . $where . " AND priority = 'Urgent'")->fetchColumn();
    return ['rows' => $rows, 'urgent_count' => $urgent];
}

function publicDistributions(PDO $db, array $filters = [], ?array $paging = null): array
{
    $s = publicSchema($db);
    $location = $s['distribution_location'] ? "COALESCE(NULLIF(TRIM(d.{$s['distribution_location']}), ''), 'Location not recorded')" : "'Location not recorded'";
    // Normalize public schedules and anonymous historical totals BEFORE counting or limiting.
    // Recipient names, contacts, staff identities and private transaction notes are never selected.
    $sql = "SELECT CONCAT('event-', e.id) AS id, e.title, e.location, e.starts_at, e.ends_at,
        e.details, e.updated_at,
        CASE WHEN e.status IN ('Upcoming','Active') AND e.ends_at IS NOT NULL AND e.ends_at <= NOW()
             THEN 'Completed' ELSE e.status END AS status,
        e.id AS source_id, NULL AS distribution_day, 1 AS time_recorded
        FROM distribution_events e WHERE e.publication_status = 'Published'
        UNION ALL
        SELECT CONCAT('history-', SUBSTRING(SHA2(CONCAT(DATE(d.{$s['distribution_date']}), CHAR(0), {$location}), 256), 1, 16)) AS id,
        'Recorded relief distribution' AS title, {$location} AS location,
        MIN(d.{$s['distribution_date']}) AS starts_at, NULL AS ends_at,
        'Summary of recorded distributions. Personal recipient information is not public.' AS details,
        MAX(d.{$s['distribution_date']}) AS updated_at, 'Completed' AS status,
        NULL AS source_id, DATE(d.{$s['distribution_date']}) AS distribution_day, " . ($s['distribution_date_only'] ? '0' : '1') . " AS time_recorded
        FROM distributions d JOIN resources r ON r.id = d.resource_id
        WHERE d.event_id IS NULL AND d.{$s['distribution_date']} <= NOW()
        GROUP BY DATE(d.{$s['distribution_date']}), {$location}";
    $where = [];
    $params = [];
    if (($term = publicSearchTerm($filters)) !== '') {
        $where[] = "(CONCAT(p.title, ' ', p.location, ' ', COALESCE(p.details, '')) LIKE ? ESCAPE '!'
            OR (p.source_id IS NOT NULL AND (
                EXISTS (SELECT 1 FROM distribution_event_resources plan JOIN resources r ON r.id = plan.resource_id
                    WHERE plan.event_id = p.source_id AND r.{$s['resource_name']} LIKE ? ESCAPE '!')
                OR EXISTS (SELECT 1 FROM distributions actual JOIN resources r ON r.id = actual.resource_id
                    WHERE actual.event_id = p.source_id AND r.{$s['resource_name']} LIKE ? ESCAPE '!')))
            OR (p.source_id IS NULL AND EXISTS (
                SELECT 1 FROM distributions d JOIN resources r ON r.id = d.resource_id
                WHERE d.event_id IS NULL AND d.{$s['distribution_date']} <= NOW()
                    AND DATE(d.{$s['distribution_date']}) = p.distribution_day AND {$location} = p.location
                    AND r.{$s['resource_name']} LIKE ? ESCAPE '!')))";
        $params = array_fill(0, 4, publicLike($term));
    }
    if (in_array($filters['status'] ?? '', ['Upcoming', 'Active', 'Completed', 'Cancelled'], true)) {
        $where[] = 'p.status = ?';
        $params[] = $filters['status'];
    }
    $from = ' FROM (' . $sql . ') p' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $order = " ORDER BY FIELD(p.status, 'Active','Upcoming','Completed','Cancelled'),
        CASE WHEN p.status = 'Upcoming' THEN p.starts_at END ASC,
        CASE WHEN p.status <> 'Upcoming' THEN p.starts_at END DESC, p.id";
    $result = publicPaginate($db, 'SELECT p.*' . $from . $order, 'SELECT COUNT(*)' . $from, $params, $paging);
    $eventIds = [];
    $history = [];
    foreach ($result['rows'] as $row) {
        if ($row['source_id'] !== null) {
            $eventIds[] = (int) $row['source_id'];
        } else {
            $history[] = $row;
        }
    }
    $byParent = [];
    // Supply details are loaded only for parent records on the selected page.
    // The union retains actual, unplanned supplies without multiplying planned quantities.
    if ($eventIds) {
        $marks = implode(', ', array_fill(0, count($eventIds), '?'));
        $stmt = $db->prepare("SELECT items.event_id, r.{$s['resource_name']} AS name, r.unit,
            plan.planned_quantity, COALESCE(SUM(actual.quantity), 0) AS distributed_quantity
            FROM (SELECT event_id, resource_id FROM distribution_event_resources WHERE event_id IN ({$marks})
                UNION SELECT event_id, resource_id FROM distributions WHERE event_id IN ({$marks})) items
            JOIN distribution_events e ON e.id = items.event_id AND e.publication_status = 'Published'
            JOIN resources r ON r.id = items.resource_id
            LEFT JOIN distribution_event_resources plan ON plan.event_id = items.event_id AND plan.resource_id = items.resource_id
            LEFT JOIN distributions actual ON actual.event_id = items.event_id AND actual.resource_id = items.resource_id
            GROUP BY items.event_id, r.id, r.{$s['resource_name']}, r.unit, plan.planned_quantity
            ORDER BY name, r.id");
        $stmt->execute(array_merge($eventIds, $eventIds));
        foreach ($stmt->fetchAll() as $item) {
            $parent = 'event-' . $item['event_id'];
            unset($item['event_id']);
            $byParent[$parent][] = $item;
        }
    }
    if ($history) {
        $selected = [];
        $historyParams = [];
        foreach ($history as $row) {
            $selected[] = 'SELECT ? AS public_id, CAST(? AS DATE) AS distribution_day, ? AS location';
            array_push($historyParams, $row['id'], $row['distribution_day'], $row['location']);
        }
        $stmt = $db->prepare("SELECT selected.public_id, r.{$s['resource_name']} AS name, r.unit,
            NULL AS planned_quantity, SUM(d.quantity) AS distributed_quantity
            FROM distributions d JOIN resources r ON r.id = d.resource_id
            JOIN (" . implode(' UNION ALL ', $selected) . ") selected
                ON DATE(d.{$s['distribution_date']}) = selected.distribution_day AND {$location} = selected.location
            WHERE d.event_id IS NULL AND d.{$s['distribution_date']} <= NOW()
            GROUP BY selected.public_id, r.id, r.{$s['resource_name']}, r.unit
            ORDER BY name, r.id");
        $stmt->execute($historyParams);
        foreach ($stmt->fetchAll() as $item) {
            $parent = $item['public_id'];
            unset($item['public_id']);
            $byParent[$parent][] = $item;
        }
    }
    foreach ($result['rows'] as &$row) {
        $row['resources'] = $byParent[$row['id']] ?? [];
        $row['time_recorded'] = (bool) $row['time_recorded'];
        if (!$row['time_recorded']) {
            $row['starts_at'] = substr($row['starts_at'], 0, 10);
            $row['updated_at'] = substr($row['updated_at'], 0, 10);
        }
        unset($row['source_id'], $row['distribution_day']);
    }
    unset($row);
    return $result;
}

function publicReport(PDO $db, ?array $paging = null): array
{
    $sectionPaging = static fn(string $section): ?array => $paging === null ? null : (is_array($paging[$section] ?? null) ? $paging[$section] : publicPageRequest([]));
    $resources = publicResources($db, [], $sectionPaging('resources'));
    $centers = publicCenters($db, [], $sectionPaging('centers'));
    $distributions = publicDistributions($db, $paging === null ? [] : ['status' => 'Completed'], $sectionPaging('distributions'));
    $s = publicSchema($db);
    // Summary cards always describe the complete published data, regardless of the visible pages.
    $resourceTotals = $db->query("SELECT COUNT(*) AS resources,
        COALESCE(SUM(CASE WHEN r.{$s['stock']} <= 0 THEN 0 WHEN r.{$s['stock']} <= r.{$s['threshold']} THEN 0 ELSE 1 END), 0) AS available_resources,
        COALESCE(SUM(r.{$s['stock']} > 0 AND r.{$s['stock']} <= r.{$s['threshold']}), 0) AS low_stock_resources,
        COALESCE(SUM(r.{$s['stock']} <= 0), 0) AS out_of_stock_resources
        FROM resources r WHERE {$s['resource_visible']}")->fetch();
    $centerTotals = $db->query("SELECT COUNT(*) AS centers,
        COALESCE(SUM(LOWER(e.status) NOT IN ('closed', 'under_maintenance', 'full') AND e.{$s['occupants']} < e.capacity), 0) AS available_centers,
        COALESCE(SUM(CASE WHEN LOWER(e.status) IN ('closed', 'under_maintenance', 'full') OR e.{$s['occupants']} >= e.capacity
            THEN 0 ELSE GREATEST(CAST(e.capacity AS SIGNED) - CAST(e.{$s['occupants']} AS SIGNED), 0) END), 0) AS available_spaces
        FROM evacuation_centers e")->fetch();
    $distributionTotals = $db->query("SELECT COALESCE(SUM(status = 'Active'), 0) AS active_distributions,
        COALESCE(SUM(status = 'Upcoming'), 0) AS upcoming_distributions
        FROM (SELECT CASE WHEN status IN ('Upcoming','Active') AND ends_at IS NOT NULL AND ends_at <= NOW()
            THEN 'Completed' ELSE status END AS status FROM distribution_events WHERE publication_status = 'Published') events")->fetch();
    $report = [
        'resources' => $resources['rows'], 'centers' => $centers['rows'], 'distributions' => $distributions['rows'],
        'summary' => array_map('intval', array_merge($resourceTotals, $centerTotals, $distributionTotals)),
    ];
    if ($paging !== null) {
        $report['pagination'] = [
            'resources' => $resources['pagination'], 'centers' => $centers['pagination'],
            'distributions' => $distributions['pagination'],
        ];
    }
    return $report;
}
