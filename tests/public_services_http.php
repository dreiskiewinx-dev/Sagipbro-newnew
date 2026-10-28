<?php
declare(strict_types=1);

/**
 * Run: php tests/public_services_http.php
 * Requires PDO MySQL, proc_open, a free loopback port 18080, and test DB privileges.
 * Uses SAGIPBRO_DB_HOST/PORT/USER/PASSWORD; NEVER selects SAGIPBRO_DB_NAME.
 * Optional SAGIPBRO_HTTP_SNAPSHOTS=1 retains TEST-only HTML snapshots in a fresh
 * temporary directory, with assets based at http://127.0.0.1:18081/.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run HTTP tests from the command line.');
}
require_once __DIR__ . '/../database/public_services_migration.php';

$project = dirname(__DIR__);
$testDatabase = 'sagipbro_test_' . bin2hex(random_bytes(10));
$createdTestDatabase = false;
$database = null;
$serverProcess = null;
$serverLog = null;
$checks = 0;
$failed = false;
$routes = ['resources.php', 'evacuation-centers.php', 'distributions.php', 'announcements.php', 'reports.php'];

function httpCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function httpFixture(PDO $database, string $table, array $values): int
{
    $statement = $database->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $database->lastInsertId();
}

function testGet(string $route, array $query = []): array
{
    $url = 'http://127.0.0.1:18080/' . $route . ($query ? '?' . http_build_query($query) : '');
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0]]);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) throw new LogicException('Local test HTTP server did not respond within five seconds.');
    $body = stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    if (!empty($meta['timed_out'])) throw new LogicException('Local test HTTP response timed out.');
    $headers = $meta['wrapper_data'] ?? [];
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $headers[0] ?? '', $match);
    return ['status' => (int) ($match[1] ?? 0), 'body' => (string) $body, 'headers' => implode("\n", $headers)];
}

function startTestServer(string $project, array $environment, string $log)
{
    $reserved = @stream_socket_server('tcp://127.0.0.1:18080', $errorNumber, $errorString);
    if ($reserved === false) throw new LogicException('Port 18080 is already in use; no existing process was stopped.');
    fclose($reserved);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', '127.0.0.1:18080', '-t', $project], [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'ab'], 2 => ['file', $log, 'ab'],
    ], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($process)) throw new LogicException('Could not start the isolated PHP test server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        if (!proc_get_status($process)['running']) {
            proc_close($process);
            throw new LogicException('The isolated PHP test server exited before becoming ready.');
        }
        $socket = @stream_socket_client('tcp://127.0.0.1:18080', $errorNumber, $errorString, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return $process;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    proc_terminate($process);
    proc_close($process);
    throw new LogicException('The isolated PHP test server did not start within five seconds.');
}

function stopTestServer(&$process): void
{
    if (is_resource($process)) {
        // Only the child represented by this proc_open handle is terminated.
        if (proc_get_status($process)['running']) proc_terminate($process);
        proc_close($process);
    }
    $process = null;
}

function csvRows(string $body): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $body);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) $rows[] = $row;
    fclose($stream);
    return $rows;
}

function checkPublicFailure(array $response, string $databaseName): void
{
    httpCheck($response['status'] === 503, 'Unavailable database/schema must return HTTP 503.');
    httpCheck(str_contains($response['body'], 'Information temporarily unavailable'), 'Outage must display an honest unavailable notice.');
    foreach (['SQLSTATE', 'PDOException', 'mysql:host=', $databaseName, 'Access denied for user', 'Stack trace:'] as $internal) {
        httpCheck(!str_contains($response['body'], $internal), 'Outage response must not expose database internals or credentials.');
    }
}

function checkCompactPublicDirectory(string $route, string $body): void
{
    $directories = [
        'resources.php' => ['resource-directory', 'resource-directory-title', ['Live inventory', 'Relief supply overview', 'Current public resource names, categories, quantities, units, and stock conditions.']],
        'evacuation-centers.php' => ['center-directory', 'center-directory-title', ['Evacuation directory', 'Find a center with space', 'Available centers appear first and are highlighted in green.']],
        'distributions.php' => ['distribution-directory', 'distribution-directory-title', ['Published operations', 'Relief distribution schedules and records', 'Active and upcoming schedules appear first.']],
        'announcements.php' => ['announcement-directory', 'announcement-directory-title', ['Community advisories', 'Current announcements', 'Published announcements remain visible until they expire or are archived.']],
        'reports.php' => ['public-reports', null, []],
    ];
    [$anchor, $removedHeading, $removedCopy] = $directories[$route];
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $body);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $section = $xpath->query('//section[@id="' . $anchor . '"]')->item(0);
    httpCheck($section instanceof DOMElement && trim($section->getAttribute('aria-label')) !== '', 'Directory must retain its anchor and an accessible name: ' . $route);
    httpCheck(!str_contains($body, 'Barangay database records') && $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " public-data-notice--success ")]')->length === 0, 'Successful data retrieval must not add the removed database notice: ' . $route);
    if ($removedHeading !== null) {
        httpCheck($xpath->query('//*[@id="' . $removedHeading . '" or @aria-labelledby="' . $removedHeading . '"]')->length === 0, 'Removed intro heading must leave no dangling accessible-name reference: ' . $route);
        $hasRemovedCopy = false;
        foreach ($removedCopy as $text) $hasRemovedCopy = $hasRemovedCopy || str_contains($body, $text);
        httpCheck(!$hasRemovedCopy, 'Removed directory intro text must not return: ' . $route);
        $action = $route . '#' . $anchor;
        httpCheck($xpath->query('//section[@id="' . $anchor . '"]//form[@role="search" and @method="get" and @action="' . $action . '"]//input[@name="q"]')->length === 1, 'Directory search must keep its GET action and result anchor: ' . $route);
        httpCheck($xpath->query('//section[@id="' . $anchor . '"]//form[@role="search"]//select[@name="' . ($route === 'announcements.php' ? 'priority' : 'status') . '"]')->length === 1, 'Directory filter must remain available: ' . $route);
        httpCheck($xpath->query('//section[@id="' . $anchor . '"]//*[@data-public-pagination="page"]//nav')->length === 1 && $xpath->query('//select[@name="per_page"]')->length === 0, 'Directory pagination keeps navigation without a page-size selector: ' . $route);
    } else {
        foreach (['resources', 'centers', 'distributions'] as $kind) {
            httpCheck($xpath->query('//section[@aria-labelledby="report-' . $kind . '"]//h2[@id="report-' . $kind . '"]')->length === 1, 'Report subsection heading must remain: ' . $kind);
            httpCheck($xpath->query('//section[@aria-labelledby="report-' . $kind . '"]//*[@data-public-pagination="' . $kind . '_page"]//nav')->length === 1 && $xpath->query('//select[@name="' . $kind . '_per_page"]')->length === 0, 'Independent report navigation remains without a page-size selector: ' . $kind);
        }
    }
}

try {
    foreach ($routes as $route) {
        httpCheck(is_file($project . '/' . $route), 'A required public page has not been created yet: ' . $route);
    }
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER');
    $password = getenv('SAGIPBRO_DB_PASSWORD');
    httpCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Invalid test database server configuration.');
    $database = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
    ]);
    $database->exec("SET time_zone = '+08:00'");
    httpCheck((bool) preg_match('/\Asagipbro_test_[a-f0-9]{20}\z/', $testDatabase), 'Unsafe generated test database identifier.');
    // Deliberately not IF NOT EXISTS: cleanup ownership requires successful creation.
    $database->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdTestDatabase = true;
    $database->exec('USE `' . $testDatabase . '`');
    $environment = getenv();
    $environment['SAGIPBRO_DB_HOST'] = $host;
    $environment['SAGIPBRO_DB_PORT'] = $port;
    $environment['SAGIPBRO_DB_USER'] = $user === false ? 'root' : $user;
    $environment['SAGIPBRO_DB_PASSWORD'] = $password === false ? '' : $password;
    $environment['SAGIPBRO_DB_NAME'] = $testDatabase;
    $environment['SAGIPBRO_BASE_URL'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $serverLog = tempnam(sys_get_temp_dir(), 'sagipbro_http_');
    if ($serverLog === false) throw new LogicException('Could not create the private HTTP test log.');
    $serverProcess = startTestServer($project, $environment, $serverLog);

    foreach ($routes as $route) checkPublicFailure(testGet($route), $testDatabase);
    checkPublicFailure(testGet('reports.php', ['export' => 'resources']), $testDatabase);
    fwrite(STDOUT, "PASS: incomplete-schema HTTP503 responses contain no SQL or credentials.\n");

    $schema = file_get_contents($project . '/database/sagipbro.sql');
    if ($schema === false) throw new LogicException('Could not read the original schema.');
    foreach (explode(';', $schema) as $statement) {
        $statement = trim($statement);
        if ($statement !== '' && !preg_match('/\A(?:CREATE\s+DATABASE\b|USE\b)/i', $statement)) $database->exec($statement);
    }
    migratePublicServices($database);
    foreach ($routes as $route) {
        $empty = testGet($route);
        httpCheck($empty['status'] === 200, 'Public empty page must load without authentication: ' . $route);
        httpCheck(str_contains($empty['body'], 'empty-state'), 'Empty database must render a genuine empty state: ' . $route);
        httpCheck(!str_contains($empty['body'], 'Interface preview only') && !str_contains($empty['body'], 'Sample snapshot'), 'Public database pages must not fall back to demo records.');
        checkCompactPublicDirectory($route, $empty['body']);
    }
    $services = testGet('services.php');
    httpCheck($services['status'] === 200, 'Services page must be public.');
    foreach ($routes as $route) httpCheck((bool) preg_match('/href="' . preg_quote($route, '/') . '(?:\?[^"#]*)?(?:#[^"]*)?"/', $services['body']), 'Service link does not target its functional public page: ' . $route);
    fwrite(STDOUT, "PASS: public service links, unauthenticated access, and empty states.\n");

    $staffId = httpFixture($database, 'users', ['full_name' => 'TEST_PRIVATE_STAFF_DO_NOT_PUBLISH', 'username' => 'test_http_staff', 'password_hash' => 'TEST-NOT-A-LOGIN-HASH', 'role' => 'admin']);
    $resources = [];
    $resourceXss = 'TEST <img src=x onerror=alert(1)>';
    $announcementXss = '<script>alert("TEST_XSS")</script>';
    foreach ([
        ['TEST HTTP Rice', 'Food', 4242, 5, 'Available'],
        ['TEST HTTP Water', 'Water', 5, 5, 'Available'],
        ['TEST HTTP Empty Kit', 'Hygiene', 0, 0, 'Available'],
        ['TEST_PRIVATE_INACTIVE_RESOURCE', 'Private category', 100, 5, 'Inactive'],
        [$resourceXss, 'Hygiene', 20, 5, 'Available'],
        ['=TEST_FORMULA(1)', '@TEST_CATEGORY', 1, 0, 'Available'],
        ['+TEST_FORMULA(2)', 'Food', 1, 0, 'Available'],
        ['-TEST_FORMULA(3)', 'Food', 1, 0, 'Available'],
        ['@TEST_FORMULA(4)', 'Food', 1, 0, 'Available'],
        ["\t=TEST_FORMULA(5)", 'Food', 1, 0, 'Available'],
    ] as [$name, $category, $stock, $threshold, $status]) {
        $resources[$name] = httpFixture($database, 'resources', ['name' => $name, 'category' => $category, 'unit' => 'packs', 'stock' => $stock, 'low_stock_threshold' => $threshold, 'status' => $status]);
    }
    foreach ([
        ['TEST HTTP Available Center', 'TEST Zone One', 100, 25, 'Open'],
        ['TEST HTTP Full Center', 'TEST Zone Two', 10, 10, 'Open'],
        ['TEST HTTP Closed Center', '=TEST_CENTER_FORMULA()', 20, 2, 'Closed'],
    ] as [$name, $address, $capacity, $occupants, $status]) {
        httpFixture($database, 'evacuation_centers', compact('name', 'address', 'capacity', 'occupants', 'status'));
    }
    $clock = $database->query("SELECT DATE_SUB(NOW(), INTERVAL 2 DAY) AS older, DATE_SUB(NOW(), INTERVAL 1 HOUR) AS recent, DATE_ADD(NOW(), INTERVAL 2 DAY) AS future")->fetch();
    foreach ([
        ['TEST HTTP Urgent Announcement', 'Published', 'Urgent', $clock['older'], null],
        ['TEST HTTP Normal Announcement', 'Published', 'Normal', $clock['recent'], null],
        ['TEST_PRIVATE_DRAFT_ANNOUNCEMENT', 'Draft', 'Urgent', $clock['older'], null],
        ['TEST_PRIVATE_FUTURE_ANNOUNCEMENT', 'Published', 'Urgent', $clock['future'], null],
        ['TEST_PRIVATE_EXPIRED_ANNOUNCEMENT', 'Published', 'Urgent', $clock['older'], $clock['recent']],
    ] as [$title, $status, $priority, $publishedAt, $expiresAt]) {
        httpFixture($database, 'announcements', ['title' => $title, 'body' => 'TEST HTTP announcement details ' . $announcementXss, 'status' => $status, 'priority' => $priority, 'published_at' => $publishedAt, 'expires_at' => $expiresAt, 'created_by' => $staffId]);
    }
    $events = [];
    foreach ([
        ['TEST HTTP Active Event', 'TEST School Distribution', 'Active', 'Published', $clock['recent']],
        ['TEST HTTP Upcoming Event', 'TEST Hall Distribution', 'Upcoming', 'Published', $clock['future']],
        ['TEST HTTP Completed Event', 'TEST Gym Distribution', 'Completed', 'Published', $clock['older']],
        ['TEST_PRIVATE_DRAFT_EVENT', 'TEST_PRIVATE_EVENT_LOCATION', 'Active', 'Draft', $clock['recent']],
    ] as [$title, $location, $status, $publicationStatus, $startsAt]) {
        $events[$title] = httpFixture($database, 'distribution_events', ['title' => $title, 'location' => $location, 'details' => 'TEST HTTP distribution instructions', 'status' => $status, 'publication_status' => $publicationStatus, 'starts_at' => $startsAt]);
    }
    foreach (['TEST HTTP Active Event', 'TEST HTTP Completed Event'] as $eventName) {
        httpFixture($database, 'distribution_event_resources', ['event_id' => $events[$eventName], 'resource_id' => $resources['TEST HTTP Rice'], 'planned_quantity' => 100]);
        httpFixture($database, 'distributions', ['event_id' => $events[$eventName], 'resource_id' => $resources['TEST HTTP Rice'], 'recipient_name' => 'TEST_PRIVATE_RECIPIENT_DO_NOT_PUBLISH', 'quantity' => 12, 'distributed_by' => $staffId, 'distributed_at' => $clock['recent']]);
    }

    $snapshots = [];
    foreach ($routes as $route) {
        $response = testGet($route);
        httpCheck($response['status'] === 200, 'Populated public page must load: ' . $route);
        httpCheck(str_contains(strtolower($response['headers']), 'cache-control: no-store'), 'Public data pages must not serve stale cached availability.');
        httpCheck(!str_contains($response['body'], 'TEST_PRIVATE_'), 'Public page exposed hidden records or personal information: ' . $route);
        httpCheck(!str_contains($response['body'], $resourceXss) && !str_contains($response['body'], $announcementXss), 'Database text was rendered as raw executable HTML: ' . $route);
        checkCompactPublicDirectory($route, $response['body']);
        $snapshots[$route] = $response['body'];
    }
    $escapedResource = testGet('resources.php', ['q' => $resourceXss]);
    httpCheck($escapedResource['status'] === 200 && !str_contains($escapedResource['body'], $resourceXss) && str_contains($escapedResource['body'], '&lt;img src=x onerror=alert(1)&gt;'), 'Resource HTML must be escaped and still visible as text, independently of its page position.');
    httpCheck(str_contains($snapshots['announcements.php'], '&lt;script&gt;'), 'Announcement HTML must be escaped and still visible as text.');
    httpCheck(strpos($snapshots['announcements.php'], 'TEST HTTP Urgent Announcement') < strpos($snapshots['announcements.php'], 'TEST HTTP Normal Announcement'), 'Urgent announcements must appear first.');

    $rice = testGet('resources.php', ['q' => 'TEST HTTP Rice']);
    httpCheck(str_contains($rice['body'], 'TEST HTTP Rice') && !str_contains($rice['body'], 'TEST HTTP Water'), 'Resource search must filter actual records.');
    httpCheck(str_contains($rice['body'], '4,242'), 'Initial actual database quantity must be displayed.');
    $statement = $database->prepare('UPDATE resources SET stock = ? WHERE id = ?');
    $statement->execute([876, $resources['TEST HTTP Rice']]);
    $updated = testGet('resources.php', ['q' => 'TEST HTTP Rice']);
    httpCheck(str_contains($updated['body'], '876') && !str_contains($updated['body'], '4,242'), 'Reload must show database changes without editing the frontend.');
    $low = testGet('resources.php', ['status' => 'Low Stock']);
    httpCheck(str_contains($low['body'], 'TEST HTTP Water') && !str_contains($low['body'], 'TEST HTTP Rice'), 'Low Stock filter must apply on the server.');
    $category = testGet('resources.php', ['category' => 'Water']);
    httpCheck(str_contains($category['body'], 'TEST HTTP Water') && !str_contains($category['body'], 'TEST HTTP Empty Kit'), 'Category filter must apply on the server.');
    $none = testGet('resources.php', ['q' => "' OR 1=1 --"]);
    httpCheck($none['status'] === 200 && !str_contains($none['body'], 'TEST HTTP Rice') && str_contains($none['body'], 'empty-state'), 'SQL injection input must produce a safe filtered empty state.');
    $centers = testGet('evacuation-centers.php', ['status' => 'Available']);
    httpCheck(str_contains($centers['body'], 'TEST HTTP Available Center') && !str_contains($centers['body'], 'TEST HTTP Full Center') && !str_contains($centers['body'], 'TEST HTTP Closed Center'), 'Center availability filter must exclude full and closed centers.');
    httpCheck((bool) preg_match('/>\s*75\s*</', $centers['body']), 'Center available spaces must show capacity minus occupants.');
    $location = testGet('evacuation-centers.php', ['q' => 'TEST Zone Two']);
    httpCheck(str_contains($location['body'], 'TEST HTTP Full Center') && !str_contains($location['body'], 'TEST HTTP Available Center'), 'Evacuation locations must be searchable.');
    $active = testGet('distributions.php', ['status' => 'Active']);
    httpCheck(str_contains($active['body'], 'TEST HTTP Active Event') && !str_contains($active['body'], 'TEST HTTP Upcoming Event'), 'Active distribution filter is not functional.');
    httpCheck(str_contains($active['body'], 'TEST School Distribution') && str_contains($active['body'], 'TEST HTTP Rice'), 'Distribution must include actual location and resources.');
    httpCheck(str_contains($active['body'], '100') && str_contains($active['body'], '12') && str_contains($active['body'], 'PHT'), 'Distribution must show planned/distributed quantities and a Philippine-time schedule.');
    $upcoming = testGet('distributions.php', ['status' => 'Upcoming']);
    httpCheck(str_contains($upcoming['body'], 'TEST HTTP Upcoming Event') && !str_contains($upcoming['body'], 'TEST HTTP Active Event'), 'Upcoming distribution filter is not functional.');
    $urgent = testGet('announcements.php', ['priority' => 'Urgent']);
    httpCheck(str_contains($urgent['body'], 'TEST HTTP Urgent Announcement') && !str_contains($urgent['body'], 'TEST HTTP Normal Announcement'), 'Urgent announcement filter is not functional.');
    httpCheck(str_contains($urgent['body'], 'TEST HTTP announcement details') && str_contains($urgent['body'], 'PHT'), 'Announcements must include details and date/time.');
    foreach ($routes as $route) {
        $malformed = testGet($route, ['q' => ['bad'], 'status' => ['bad'], 'category' => ['bad'], 'priority' => ['bad'], 'export' => ['bad']]);
        httpCheck($malformed['status'] === 200 && !str_contains($malformed['body'], 'Fatal error'), 'Array-valued GET parameters must be handled safely: ' . $route);
    }
    fwrite(STDOUT, "PASS: database-backed reloads, search/filter interactions, schedule details, XSS escaping, and malformed requests.\n");

    foreach (['resources', 'centers', 'distributions'] as $kind) {
        $csv = testGet('reports.php', ['export' => $kind]);
        httpCheck($csv['status'] === 200, 'CSV export must return HTTP 200.');
        httpCheck(str_contains(strtolower($csv['headers']), 'content-type: text/csv') && str_contains(strtolower($csv['headers']), 'content-disposition: attachment'), 'Report export must be a downloadable CSV.');
        httpCheck(!str_contains($csv['body'], '<!doctype') && !str_contains($csv['body'], 'TEST_PRIVATE_'), 'CSV must contain only public report data, not layout or personal records.');
        $rows = csvRows($csv['body']);
        httpCheck(count($rows) > 1, 'CSV must include actual records and a header.');
        foreach ($rows as $row) foreach ($row as $cell) httpCheck(!preg_match('/\A\s*[=+@-]/u', (string) $cell), 'CSV formula injection must be neutralized.');
        if ($kind === 'resources') {
            $names = array_column($rows, 0);
            foreach (['=TEST_FORMULA(1)', '+TEST_FORMULA(2)', '-TEST_FORMULA(3)', '@TEST_FORMULA(4)', "\t=TEST_FORMULA(5)"] as $formula) {
                httpCheck(in_array("'" . $formula, $names, true), 'Dangerous spreadsheet prefixes must receive a leading apostrophe.');
            }
        }
    }
    fwrite(STDOUT, "PASS: downloadable public CSV exports and spreadsheet formula-injection defenses.\n");

    if (getenv('SAGIPBRO_HTTP_SNAPSHOTS') === '1') {
        $snapshotDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-http-snapshots-' . bin2hex(random_bytes(10));
        if (!mkdir($snapshotDirectory, 0700)) throw new LogicException('Could not create TEST-only snapshot directory.');
        foreach ($snapshots as $route => $body) {
            $body = str_replace('<head>', '<head><base href="http://127.0.0.1:18081/">', $body);
            if (file_put_contents($snapshotDirectory . DIRECTORY_SEPARATOR . str_replace('.php', '.html', $route), $body) === false) throw new LogicException('Could not save TEST-only visual snapshot.');
        }
        fwrite(STDOUT, 'TEST-only visual snapshots retained at: ' . $snapshotDirectory . "\n");
    }

    stopTestServer($serverProcess);
    // A nonexistent database tests connection failure, without touching a real DB.
    $environment['SAGIPBRO_DB_NAME'] = $testDatabase . '_missing';
    $serverProcess = startTestServer($project, $environment, $serverLog);
    foreach ($routes as $route) checkPublicFailure(testGet($route), $testDatabase);
    stopTestServer($serverProcess);
    $log = file_get_contents($serverLog);
    httpCheck($log !== false && !preg_match('/PHP (?:Warning|Fatal error)|Uncaught (?:Error|Exception)/', $log), 'HTTP requests emitted PHP warnings or fatal errors.');
    fwrite(STDOUT, "PASS: unavailable-database responses are safe and no PHP warnings were emitted.\n");
} catch (Throwable $error) {
    $failed = true;
    $message = $error instanceof LogicException ? $error->getMessage() : get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine();
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
} finally {
    stopTestServer($serverProcess);
    if ($createdTestDatabase && $database instanceof PDO && preg_match('/\Asagipbro_test_[a-f0-9]{20}\z/', $testDatabase)) {
        try {
            $database->exec('DROP DATABASE `' . $testDatabase . '`');
            fwrite(STDOUT, "Cleaned up the private HTTP test database; application data was untouched.\n");
        } catch (Throwable $cleanupError) {
            $failed = true;
            fwrite(STDERR, 'Cleanup pending for private test database ' . $testDatabase . ". No other database is a cleanup target.\n");
        }
    }
    if (is_string($serverLog) && is_file($serverLog)) unlink($serverLog);
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED' : 'PASSED') . ': ' . $checks . " HTTP assertions.\n");
exit($failed ? 1 : 0);
