<?php
declare(strict_types=1);

/**
 * Run: php tests/public_pagination_http.php
 * Requires PDO MySQL, DOM, proc_open and CREATE/DROP DATABASE privileges.
 * Creates its own randomly named database and loopback HTTP server. Never selects
 * SAGIPBRO_DB_NAME or changes application records. Exercises both schema variants.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run pagination tests from the command line.');
}
require_once __DIR__ . '/../database/public_services_migration.php';

$project = dirname(__DIR__);
$testDatabase = 'sagipbro_public_page_' . bin2hex(random_bytes(10));
$createdDatabase = false;
$database = null;
$server = null;
$serverLog = null;
$serverAddress = '';
$checks = 0;
$failed = false;

function ppCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function ppFixture(PDO $database, string $table, array $values): int
{
    $statement = $database->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $database->lastInsertId();
}

function ppStartServer(string $project, array $environment, string $log): mixed
{
    global $serverAddress;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if (!$socket) throw new LogicException('Could not reserve a private loopback port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $serverAddress = 'http://' . $address;
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', $address, '-t', $project], [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'ab'], 2 => ['file', $log, 'ab'],
    ], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($process)) throw new LogicException('Could not start the isolated PHP HTTP server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        if (!proc_get_status($process)['running']) {
            proc_close($process);
            throw new LogicException('The isolated HTTP server exited before becoming ready.');
        }
        $probe = @stream_socket_client('tcp://' . $address, $errorNumber, $errorString, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            return $process;
        }
        usleep(25000);
    } while (microtime(true) < $deadline);
    proc_terminate($process);
    proc_close($process);
    throw new LogicException('The isolated HTTP server did not start within five seconds.');
}

function ppStopServer(mixed &$process): void
{
    if (is_resource($process)) {
        if (proc_get_status($process)['running']) proc_terminate($process);
        proc_close($process);
    }
    $process = null;
}

function ppGet(string $route, array $query = []): array
{
    global $serverAddress;
    $url = str_starts_with($route, 'http://') ? $route : $serverAddress . '/' . $route;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $stream = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]));
    if ($stream === false) throw new LogicException('The isolated HTTP server did not respond: ' . $route);
    $body = (string) stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    if (!empty($meta['timed_out'])) throw new LogicException('HTTP request timed out: ' . $route);
    $headers = $meta['wrapper_data'] ?? [];
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $headers[0] ?? '', $match);
    $status = (int) ($match[1] ?? 0);
    ppCheck($status === 200, $route . ' must return HTTP 200, got ' . $status . '.');
    ppCheck(!str_contains($body, 'PRIVATE_PAG_'), $route . ' exposed hidden records or personal recipient information.');
    return ['body' => $body, 'headers' => implode("\n", $headers), 'status' => $status];
}

function ppDom(string $body): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $body);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function ppItems(DOMXPath $xpath, array $case): array
{
    $nodes = $xpath->query($case['items']);
    return array_map(static fn(DOMNode $node): string => trim($node->textContent), iterator_to_array($nodes));
}

function ppFooter(DOMXPath $xpath, array $case): DOMNode
{
    $pageKey = $case['page'] ?? 'page';
    $footer = $xpath->query('//*[@data-public-pagination="' . $pageKey . '"]')->item(0);
    ppCheck($footer instanceof DOMNode, $case['route'] . ' must expose its pagination footer.');
    return $footer;
}

function ppControl(DOMXPath $xpath, DOMNode $footer, string $label): DOMElement
{
    $nodes = $xpath->query('.//*[self::a or self::button or self::span][@aria-label="' . $label . '"]', $footer);
    if ($nodes->length === 0) $nodes = $xpath->query('.//*[self::a or self::button or self::span][normalize-space()="' . $label . '"]', $footer);
    $node = $nodes->item(0);
    ppCheck($node instanceof DOMElement, 'Pagination must expose its ' . $label . ' control.');
    return $node;
}

function ppDisabled(DOMElement $node): bool
{
    for ($current = $node, $level = 0; $current instanceof DOMElement && $level < 2; $current = $current->parentNode, ++$level) {
        if ($current->getAttribute('aria-disabled') === 'true' || $current->hasAttribute('disabled') || str_contains(' ' . $current->getAttribute('class') . ' ', ' disabled ')) return true;
    }
    return false;
}

function ppHrefQuery(DOMElement $node): array
{
    $query = [];
    parse_str((string) parse_url($node->getAttribute('href'), PHP_URL_QUERY), $query);
    return $query;
}

function ppSummary(DOMNode $footer, int $from, int $to, int $total, string $label): void
{
    $text = preg_replace('/\s+/u', ' ', str_replace(',', '', $footer->textContent));
    ppCheck((bool) preg_match('/Showing\s+' . $from . '\s*[\x{2013}\x{2014}-]\s*' . $to . '\s+of\s+' . $total . '\s+records/u', $text), $label . ' has incorrect pagination totals: ' . $text);
}

function ppPageSet(array $case): void
{
    $route = $case['route'];
    $total = $case['total'];
    $pageKey = $case['page'] ?? 'page';
    $sizeKey = $case['size'] ?? 'per_page';
    $query = $case['query'] ?? [];
    $first = ppDom(ppGet($route, $query)['body']);
    $firstItems = ppItems($first, $case);
    ppCheck(count($firstItems) === min(6, $total), $route . ' must default to six records per section.');
    $footer = ppFooter($first, $case);
    ppSummary($footer, $total ? 1 : 0, min(6, $total), $total, $route);
    ppCheck(ppDisabled(ppControl($first, $footer, 'Previous')), $route . ' Previous must be disabled on the first page.');
    ppCheck(ppDisabled(ppControl($first, $footer, 'Next')) === ($total <= 6), $route . ' Next first-page state is wrong.');
    ppCheck($first->query('.//select | .//form', $footer)->length === 0, $route . ' has fixed page sizes without a dropdown or Apply form.');
    if ($total > 6) {
        $next = ppControl($first, $footer, 'Next');
        $nextQuery = ppHrefQuery($next);
        ppCheck((int) ($nextQuery[$pageKey] ?? 0) === 2, $route . ' Next link must load page two.');
        ppCheck(!isset($nextQuery[$sizeKey]), $route . ' navigation must omit obsolete page-size parameters.');
        foreach ($query as $key => $value) ppCheck((string) ($nextQuery[$key] ?? '') === (string) $value, $route . ' Next must preserve ' . $key . '.');
        $second = ppDom(ppGet($route, $nextQuery)['body']);
        $secondFooter = ppFooter($second, $case);
        ppSummary($secondFooter, 7, min(12, $total), $total, $route);
        ppCheck(!ppDisabled(ppControl($second, $secondFooter, 'Previous')), $route . ' Previous must enable on page two.');
        ppCheck((int) (ppHrefQuery(ppControl($second, $secondFooter, 'Previous'))[$pageKey] ?? 0) === 1, $route . ' Previous link must return to page one.');
        $returned = ppDom(ppGet($route, ppHrefQuery(ppControl($second, $secondFooter, 'Previous')))['body']);
        ppCheck(ppItems($returned, $case) === $firstItems, $route . ' Previous must reload the same first six records.');
        ppCheck(array_intersect($firstItems, ppItems($second, $case)) === [], $route . ' adjacent pages must not duplicate records.');
    }
    foreach ([6, 10, 25, 50, 100] as $size) {
        $page = min(2, max(1, (int) ceil($total / 6)));
        $from = $total ? ($page - 1) * 6 + 1 : 0;
        $to = min($page * 6, $total);
        $dom = ppDom(ppGet($route, array_merge($query, [$pageKey => 2, $sizeKey => $size]))['body']);
        ppCheck(count(ppItems($dom, $case)) === max(0, $to - $from + ($total ? 1 : 0)), $route . ' must stay at six records when a different size is requested.');
        ppSummary(ppFooter($dom, $case), $from, $to, $total, $route);
        ppCheck($dom->query('//select[@name="' . $sizeKey . '"]')->length === 0, $route . ' must not render a page-size dropdown.');
    }
    $last = ppDom(ppGet($route, array_merge($query, [$pageKey => 999999]))['body']);
    $lastPage = max(1, (int) ceil($total / 6));
    $lastFooter = ppFooter($last, $case);
    ppSummary($lastFooter, $total ? ($lastPage - 1) * 6 + 1 : 0, $total, $total, $route);
    ppCheck(ppDisabled(ppControl($last, $lastFooter, 'Next')), $route . ' Next must be disabled on the last page.');
    $invalid = ppDom(ppGet($route, array_merge($query, [$pageKey => ['bad'], $sizeKey => ['bad']]))['body']);
    ppSummary(ppFooter($invalid, $case), $total ? 1 : 0, min(6, $total), $total, $route);
    foreach ([5, 7] as $unsupportedSize) {
        $unsupported = ppDom(ppGet($route, array_merge($query, [$pageKey => 0, $sizeKey => $unsupportedSize]))['body']);
        ppSummary(ppFooter($unsupported, $case), $total ? 1 : 0, min(6, $total), $total, $route . ' unsupported-size fallback');
    }
    $seen = [];
    for ($page = 1; $page <= $lastPage; ++$page) {
        $dom = ppDom(ppGet($route, array_merge($query, [$pageKey => $page]))['body']);
        foreach (ppItems($dom, $case) as $item) {
            ppCheck(!isset($seen[$item]), $route . ' must use stable ordering without duplicate or skipped records.');
            $seen[$item] = true;
        }
    }
    ppCheck(count($seen) === $total, $route . ' must make every matching record accessible.');
}

function ppSchema(PDO $database, string $project): void
{
    $schema = file_get_contents($project . '/database/sagipbro.sql');
    if ($schema === false) throw new LogicException('Could not read the base schema.');
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '' && !preg_match('/\A(?:CREATE\s+DATABASE\b|USE\b)/i', $statement)) $database->exec($statement);
    }
    migratePublicServices($database);
}

function ppSeed(PDO $database): array
{
    $staff = ppFixture($database, 'users', ['full_name' => 'PRIVATE_PAG_STAFF', 'username' => 'private_pagination_staff', 'password_hash' => 'TEST-NOT-LOGIN', 'role' => 'admin']);
    $resources = [];
    $centers = [];
    $announcements = [];
    $clock = $database->query("SELECT DATE_SUB(NOW(), INTERVAL 1 DAY) AS past, DATE_ADD(NOW(), INTERVAL 5 DAY) AS future")->fetch(PDO::FETCH_ASSOC);
    for ($i = 0; $i < 107; ++$i) {
        $suffix = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $resources[] = ppFixture($database, 'resources', ['name' => ($i === 106 ? 'PAG_%! Resource' : 'PAG Resource') . $suffix, 'category' => $i % 2 === 0 ? 'Food' : 'Water', 'unit' => 'packs', 'stock' => [0, 5, 40][$i % 3], 'low_stock_threshold' => 5]);
        $centers[] = ppFixture($database, 'evacuation_centers', ['name' => ($i === 106 ? 'PAG_%! Center' : 'PAG Center') . $suffix, 'address' => 'PAG Zone' . $suffix, 'capacity' => 100, 'occupants' => $i % 3 === 1 ? 100 : 25, 'status' => $i % 3 === 2 ? 'Closed' : 'Open']);
        $announcements[] = ppFixture($database, 'announcements', ['title' => ($i === 106 ? 'PAG_%! Announcement' : 'PAG Announcement') . $suffix, 'body' => 'PAG Advisory details ' . $suffix, 'status' => 'Published', 'priority' => $i % 2 === 0 ? 'Urgent' : 'Normal', 'published_at' => $clock['past'], 'created_by' => $staff]);
    }
    ppFixture($database, 'resources', ['name' => 'PRIVATE_PAG_INACTIVE_RESOURCE', 'category' => 'Private', 'unit' => 'packs', 'stock' => 100, 'status' => 'Inactive']);
    foreach ([['Draft', $clock['past'], null], ['Archived', $clock['past'], null], ['Published', $clock['future'], null], ['Published', $clock['past'], $clock['past']]] as $i => [$status, $published, $expires]) {
        ppFixture($database, 'announcements', ['title' => 'PRIVATE_PAG_ANNOUNCEMENT_' . $i, 'body' => 'PRIVATE_PAG_BODY', 'status' => $status, 'published_at' => $published, 'expires_at' => $expires, 'created_by' => $staff]);
    }
    $events = [];
    for ($i = 0; $i < 113; ++$i) {
        $suffix = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $events[] = $event = ppFixture($database, 'distribution_events', ['title' => ($i === 106 ? 'PAG_%! Event' : 'PAG Event') . $suffix, 'location' => 'PAG Venue' . $suffix, 'details' => 'PAG Verified distribution details', 'starts_at' => $i < 110 ? $clock['past'] : $clock['future'], 'status' => $i < 107 ? 'Completed' : ($i < 110 ? 'Active' : 'Upcoming'), 'publication_status' => 'Published']);
        ppFixture($database, 'distribution_event_resources', ['event_id' => $event, 'resource_id' => $resources[$i % 107], 'planned_quantity' => 10]);
        ppFixture($database, 'distributions', ['event_id' => $event, 'resource_id' => $resources[$i % 107], 'recipient_name' => 'PRIVATE_PAG_RECIPIENT', 'remarks' => 'PRIVATE_PAG_RELEASE_NOTES', 'quantity' => 2, 'distributed_by' => $staff, 'distributed_at' => $clock['past']]);
    }
    $draft = ppFixture($database, 'distribution_events', ['title' => 'PRIVATE_PAG_DRAFT_EVENT', 'location' => 'PRIVATE_PAG_LOCATION', 'starts_at' => $clock['past'], 'status' => 'Completed', 'publication_status' => 'Draft']);
    ppFixture($database, 'distributions', ['event_id' => $draft, 'resource_id' => $resources[0], 'recipient_name' => 'PRIVATE_PAG_DRAFT_RECIPIENT', 'quantity' => 99, 'distributed_by' => $staff]);
    for ($i = 1; $i <= 3; ++$i) {
        $date = (new DateTimeImmutable($clock['past']))->modify('-' . $i . ' days')->format('Y-m-d H:i:s');
        ppFixture($database, 'distributions', ['resource_id' => $resources[$i], 'recipient_name' => 'PRIVATE_PAG_HISTORY_RECIPIENT', 'remarks' => 'PRIVATE_PAG_HISTORY_NOTES', 'quantity' => 3, 'distributed_by' => $staff, 'distributed_at' => $date]);
        // Same date/resource: one public historical group, not one card per recipient.
        ppFixture($database, 'distributions', ['resource_id' => $resources[$i], 'recipient_name' => 'PRIVATE_PAG_OTHER_RECIPIENT', 'quantity' => 4, 'distributed_by' => $staff, 'distributed_at' => $date]);
    }
    ppFixture($database, 'distributions', ['resource_id' => $resources[0], 'recipient_name' => 'PRIVATE_PAG_FUTURE_RECIPIENT', 'quantity' => 7, 'distributed_by' => $staff, 'distributed_at' => $clock['future']]);
    return compact('resources', 'centers', 'announcements', 'events');
}

function ppLegacySchema(PDO $database): void
{
    $database->exec('ALTER TABLE resources CHANGE name resource_name VARCHAR(120) NOT NULL, CHANGE stock quantity INT UNSIGNED NOT NULL DEFAULT 0, CHANGE low_stock_threshold minimum_stock INT UNSIGNED NOT NULL DEFAULT 10');
    $database->exec('ALTER TABLE evacuation_centers CHANGE name center_name VARCHAR(150) NOT NULL, CHANGE address location VARCHAR(255) NOT NULL, CHANGE occupants current_occupants INT UNSIGNED NOT NULL DEFAULT 0');
    $database->exec('ALTER TABLE announcements CHANGE body content TEXT NOT NULL');
    $database->exec('ALTER TABLE distributions CHANGE distributed_at distribution_date DATE NOT NULL, CHANGE location distribution_location VARCHAR(255) NULL');
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER');
    $password = getenv('SAGIPBRO_DB_PASSWORD');
    ppCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Invalid database server configuration.');
    $database = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $database->exec("SET time_zone = '+08:00'");
    ppCheck((bool) preg_match('/\Asagipbro_public_page_[a-f0-9]{20}\z/', $testDatabase), 'Unsafe temporary database identifier.');
    $database->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabase = true;
    $database->exec('USE `' . $testDatabase . '`');
    ppSchema($database, $project);
    $fixtures = ppSeed($database);
    $environment = getenv();
    $environment['SAGIPBRO_DB_HOST'] = $host;
    $environment['SAGIPBRO_DB_PORT'] = $port;
    $environment['SAGIPBRO_DB_USER'] = $user === false ? 'root' : $user;
    $environment['SAGIPBRO_DB_PASSWORD'] = $password === false ? '' : $password;
    $environment['SAGIPBRO_DB_NAME'] = $testDatabase;
    $environment['SAGIPBRO_BASE_URL'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $serverLog = tempnam(sys_get_temp_dir(), 'sagipbro_public_page_');
    if ($serverLog === false) throw new LogicException('Could not create private server log.');

    $cases = [
        ['route' => 'resources.php', 'total' => 107, 'items' => '//div[@id="resourceGrid"]/article//h2'],
        ['route' => 'evacuation-centers.php', 'total' => 107, 'items' => '//section[@id="center-directory"]//article//h2'],
        ['route' => 'announcements.php', 'total' => 107, 'items' => '//section[@id="announcement-directory"]//article//h2'],
        ['route' => 'distributions.php', 'total' => 116, 'items' => '//section[@id="distribution-directory"]//article/div[contains(@class,"public-card-header")]/h2 | //section[@id="distribution-directory"]//article/p[1]'],
        ['route' => 'reports.php', 'total' => 107, 'page' => 'resources_page', 'size' => 'resources_per_page', 'items' => '//section[@aria-labelledby="report-resources"]//tbody/tr/th'],
        ['route' => 'reports.php', 'total' => 107, 'page' => 'centers_page', 'size' => 'centers_per_page', 'items' => '//section[@aria-labelledby="report-centers"]//tbody/tr/th'],
        ['route' => 'reports.php', 'total' => 110, 'page' => 'distributions_page', 'size' => 'distributions_per_page', 'items' => '//section[@aria-labelledby="report-distributions"]//tbody/tr'],
    ];
    // Historical distributions share a title; use their card text/location as identity.
    $cases[3]['items'] = '//section[@id="distribution-directory"]//article';
    foreach (['original', 'legacy'] as $variant) {
        if ($variant === 'legacy') ppLegacySchema($database);
        $server = ppStartServer($project, $environment, $serverLog);
        foreach ($cases as $case) ppPageSet($case);
        fwrite(STDOUT, 'PASS: ' . $variant . " schema directories/reports, fixed page sizes, linked navigation, boundaries, totals, and stable ordering.\n");

        $filters = [
            [0, ['q' => 'PAG Resource', 'category' => 'Food', 'status' => 'Low Stock'], 17],
            [0, ['q' => 'PAG_%!', 'category' => 'Food', 'status' => 'Low Stock'], 1],
            [1, ['q' => 'PAG Center', 'status' => 'Available'], 36],
            [1, ['q' => 'PAG_%!', 'status' => 'Full'], 1],
            [2, ['q' => 'PAG Announcement', 'priority' => 'Normal'], 53],
            [2, ['q' => 'PAG_%!', 'priority' => 'Urgent'], 1],
            [3, ['q' => 'PAG Event', 'status' => 'Completed'], 106],
            [3, ['q' => 'PAG_%!', 'status' => 'Completed'], 1],
        ];
        foreach ($filters as [$index, $query, $total]) ppPageSet(array_merge($cases[$index], ['query' => $query, 'total' => $total]));
        foreach (array_slice($cases, 0, 4) as $case) {
            foreach (['NO_SUCH_PUBLIC_PAGE_RECORD_908b', "' OR 1=1 --"] as $term) {
                $dom = ppDom(ppGet($case['route'], ['q' => $term, 'page' => 99])['body']);
                ppCheck(ppItems($dom, $case) === [], $case['route'] . ' must not return unrelated records for empty or malicious searches.');
                $footer = ppFooter($dom, $case);
                ppSummary($footer, 0, 0, 0, $case['route']);
                ppCheck(ppDisabled(ppControl($dom, $footer, 'Previous')) && ppDisabled(ppControl($dom, $footer, 'Next')), 'Both controls must be disabled for empty results.');
            }
        }
        fwrite(STDOUT, 'PASS: ' . $variant . " schema combined filters, literal wildcard searches, empty results, and privacy/publication rules.\n");

        foreach ([
            [['resources_page' => 3, 'resources_per_page' => 25, 'centers_page' => 2, 'centers_per_page' => 50, 'distributions_page' => 11, 'distributions_per_page' => 10], [[4, 13, 18, 107], [5, 7, 12, 107], [6, 61, 66, 110]]],
            [['resources_page' => 3, 'resources_per_page' => 6, 'centers_page' => 2, 'centers_per_page' => 6, 'distributions_page' => 19, 'distributions_per_page' => 6], [[4, 13, 18, 107], [5, 7, 12, 107], [6, 109, 110, 110]]],
        ] as [$independentQuery, $expectedSections]) {
            $dom = ppDom(ppGet('reports.php', $independentQuery)['body']);
            foreach ($expectedSections as [$index, $from, $to, $total]) {
                $footer = ppFooter($dom, $cases[$index]);
                ppSummary($footer, $from, $to, $total, 'Independent report sections');
                $control = ppControl($dom, $footer, 'Previous');
                $links = ppHrefQuery($control);
                foreach ($independentQuery as $key => $value) {
                    if ($key === $cases[$index]['page'] || str_ends_with($key, '_per_page')) continue;
                    ppCheck((int) ($links[$key] ?? 0) === $value, 'Report navigation must preserve the other sections: ' . $key);
                }
            }
        }
        $summary = $dom->query('//div[contains(@class,"public-summary-grid")]')->item(0);
        ppCheck($summary instanceof DOMNode && str_contains($summary->textContent, '107') && str_contains($summary->textContent, '2,700'), 'Report summary must count the complete database, including all available spaces.');
        foreach (['resources' => 107, 'centers' => 107, 'distributions' => 116] as $kind => $expected) {
            $csv = ppGet('reports.php', array_merge($independentQuery, ['export' => $kind]));
            ppCheck(str_contains(strtolower($csv['headers']), 'content-type: text/csv'), 'CSV download must retain its MIME type.');
            $lines = preg_split('/\r?\n/', trim($csv['body']));
            ppCheck(count($lines) === $expected + 1, 'CSV must export all public ' . $kind . ', not only the visible page.');
        }
        fwrite(STDOUT, 'PASS: ' . $variant . " independent report sections, complete summary totals, and full CSV exports.\n");
        ppStopServer($server);
    }

    // Make a formerly valid last page disappear, using only our private fixture DB.
    $database->exec('UPDATE resources SET status = \'Inactive\' WHERE id IN (' . implode(',', array_slice($fixtures['resources'], 100)) . ')');
    $database->exec('DELETE FROM evacuation_centers WHERE id IN (' . implode(',', array_slice($fixtures['centers'], 100)) . ')');
    $database->exec('UPDATE announcements SET status = \'Archived\' WHERE id IN (' . implode(',', array_slice($fixtures['announcements'], 100)) . ')');
    $server = ppStartServer($project, $environment, $serverLog);
    foreach (array_slice($cases, 0, 3) as $case) {
        $dom = ppDom(ppGet($case['route'], ['page' => 18])['body']);
        ppSummary(ppFooter($dom, $case), 97, 100, 100, 'Clamping after record removal');
        ppCheck(count(ppItems($dom, $case)) === 4, 'Reload must clamp stale last-page requests after deletion or archiving.');
    }
    $database->exec("UPDATE resources SET resource_name = 'PAG Freshly edited resource' WHERE id = " . (int) $fixtures['resources'][0]);
    $edited = ppDom(ppGet('resources.php', ['q' => 'PAG Freshly edited resource', 'page' => 18])['body']);
    ppSummary(ppFooter($edited, $cases[0]), 1, 1, 1, 'Edited record lookup');
    ppCheck(count(ppItems($edited, $cases[0])) === 1, 'Edited database records must be reflected without stale pagination.');
    ppStopServer($server);
    $log = file_get_contents($serverLog);
    ppCheck($log !== false && !preg_match('/PHP (?:Warning|Fatal error)|Uncaught (?:Error|Exception)/', $log), 'HTTP requests emitted PHP warnings or fatal errors.');
    fwrite(STDOUT, "PASS: mutations recompute counts, clamp stale pages, and refresh search results without PHP warnings.\n");
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . ($error instanceof LogicException ? $error->getMessage() : get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine()) . "\n");
} finally {
    ppStopServer($server);
    if ($createdDatabase && $database instanceof PDO && preg_match('/\Asagipbro_public_page_[a-f0-9]{20}\z/', $testDatabase)) {
        try {
            $database->exec('DROP DATABASE `' . $testDatabase . '`');
            fwrite(STDOUT, "Cleaned up private pagination fixtures; application records were untouched.\n");
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, 'Cleanup pending for private database ' . $testDatabase . ". No application database is a cleanup target.\n");
        }
    }
    if (is_string($serverLog) && is_file($serverLog)) unlink($serverLog);
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED' : 'PASSED') . ': ' . $checks . " public pagination assertions.\n");
exit($failed ? 1 : 0);
