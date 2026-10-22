<?php
declare(strict_types=1);

/**
 * CLI-only isolated homepage/announcement regressions.
 * Run: php tests/homepage_announcements_http.php
 * Optional Chrome layout checks: SAGIPBRO_HOMEPAGE_BROWSER=1.
 * Requires MySQL CREATE/DROP DATABASE, DOM, and proc_open. Application records
 * are never selected or modified; a random private database is always removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../database/public_services_migration.php';
require_once __DIR__ . '/../includes/public_data.php';

$project = dirname(__DIR__);
$name = 'sagipbro_home_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-home-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $checks = 0; $failed = false; $address = '';

function hmCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function hmInsert(PDO $db, string $table, array $values): int
{
    $statement = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $db->lastInsertId();
}

function hmGet(string $route, int $expected = 200): array
{
    global $address;
    $url = str_starts_with($route, 'http://') ? $route : $address . '/' . ltrim($route, '/');
    $stream = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0]]));
    if (!$stream) throw new LogicException('Private HTTP server unavailable: ' . $route);
    $body = (string) stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    hmCheck(str_contains($meta['wrapper_data'][0] ?? '', ' ' . $expected . ' '), $route . ' expected HTTP ' . $expected . ', got ' . ($meta['wrapper_data'][0] ?? 'no status'));
    hmCheck(!str_contains($body, 'HOME_PRIVATE_'), $route . ' exposed a non-public announcement.');
    hmCheck(!str_contains($body, 'SQLSTATE') && !preg_match('/Fatal error|Warning:|Stack trace:/i', $body), $route . ' exposed technical errors.');
    if (parse_url($url, PHP_URL_PATH) === '/index.php') hmHomepageAnnouncementJump($body);
    return ['body' => $body, 'headers' => implode("\n", $meta['wrapper_data'] ?? [])];
}

function hmDom(string $body): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $body);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function hmClass(string $class): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
}

function hmHomepageAnnouncementJump(string $body): void
{
    $dom = hmDom($body);
    $actions = '//section[' . hmClass('home-hero') . ']//*[' . hmClass('hero-actions') . ']';
    hmCheck($dom->query($actions . '/a')->length === 3 && $dom->query($actions . '/a[@href="resources.php#resource-directory"]')->length === 1 && $dom->query($actions . '/a[@href="evacuation-centers.php#center-directory"]')->length === 1, 'Homepage keeps its two original hero actions alongside one announcement action.');
    $jump = $dom->query($actions . '/a[@href="#latest-announcements"]');
    hmCheck($jump->length === 1 && trim($jump->item(0)->textContent) === 'View announcements' && $dom->query('.//i[' . hmClass('bi-megaphone') . ']', $jump->item(0))->length === 1, 'Announcement action uses the native homepage target and megaphone label.');
    hmCheck($dom->query('//*[@id="latest-announcements"]')->length === 1 && $dom->query('//section[@id="latest-announcements" and @aria-labelledby="latest-announcements-title"]//h2[@id="latest-announcements-title"]')->length === 1, 'Announcement action targets one existing, named homepage feed in populated, empty and outage states.');
    hmCheck($dom->query('//section[@id="latest-announcements"]/preceding-sibling::*[1][' . hmClass('emergency-band') . ']')->length === 1 && $dom->query('//section[@id="latest-announcements"]/following-sibling::*[1][@aria-labelledby="quick-info-title"]')->length === 1, 'Announcements appear immediately after the advisory and before unchanged Quick Information in every data state.');
    hmCheck($dom->query('//*[@id="latest-announcements"]//a[' . hmClass('home-announcements-all') . ' and @href="announcements.php#announcement-directory"]')->length === 1, 'View All always opens the existing complete announcement directory.');
}

function hmCards(DOMXPath $dom): array
{
    return iterator_to_array($dom->query('//*[@id="latest-announcements"]//a[' . hmClass('home-announcement-card') . ']'));
}

function hmLinkId(DOMElement $link): int
{
    parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
    return (int) ($query['view'] ?? 0);
}

function hmPreserved(string $html): void
{
    global $project;
    preg_match('~<section class="section-space" aria-labelledby="quick-info-title">[\s\S]*?</section>~', $html, $match);
    hmCheck(hash('sha256', str_replace("\r\n", "\n", $match[0] ?? '')) === '7540c1a6ee75f88dca021bda27b9811f8c4410d4e53da2bf1d19a374d3e7a55f', 'Quick Information markup, cards, and links must remain unchanged.');
    preg_match('~<section class="section-space section-soft" aria-labelledby="snapshot-title">[\s\S]*?</section>~', (string) file_get_contents($project . '/index.php'), $snapshotSource);
    hmCheck(hash('sha256', str_replace("\r\n", "\n", $snapshotSource[0] ?? '')) === '3957623ad2e26b023269ee985de56ec65d47ce104766b484ec20f6d7701389b1', 'Readiness section markup and its live-data calculation must remain unchanged.');
    hmCheck(hash_file('sha256', $project . '/includes/footer.php') === '33b5c5b88562065629219d6a6b99ae1f5df0a822c47a2085aaa494458191bf52', 'Footer source must remain unchanged.');
    hmCheck(hash_file('sha256', $project . '/includes/navbar.php') === 'd20ae46eb48f4e68b424cfa123c8f39c18943fcd4d63f4ae5e1de65ca67eef87', 'Navbar source must remain unchanged.');
    $dom = hmDom($html);
    hmCheck($dom->query('//section[@aria-labelledby="snapshot-title"]//*[contains(@class,"public-data-notice--success")]')->length === 1, 'Readiness preserves database records notice.');
    $snapshot = $dom->query('//section[@aria-labelledby="snapshot-title"]')->item(0);
    hmCheck($snapshot instanceof DOMNode && str_contains($snapshot->textContent, 'HOME Water') && str_contains($snapshot->textContent, '37') && str_contains($snapshot->textContent, '78'), 'Readiness shows real inventory and available spaces.');
    hmCheck(!str_contains($snapshot->textContent, 'HOME Inactive'), 'Inactive inventory remains private.');
}

function hmFeed(PDO $db, array $ids, ?int $urgentId): void
{
    $feed = publicHomepageAnnouncements($db);
    hmCheck(array_map('intval', array_column($feed['rows'], 'id')) === $ids, 'Homepage query returns the priority-selected feature plus up to three distinct urgent-first recent notices.');
    hmCheck(($feed['urgent'] === null ? null : (int) $feed['urgent']['id']) === $urgentId, 'Urgent advisory uses the featured notice only when its priority is Urgent.');
    hmCheck(count($feed['rows']) <= 4 && count(array_unique(array_column($feed['rows'], 'id'))) === count($feed['rows']), 'Featured and recent query results never duplicate or exceed four records.');
}

function hmFeatured(DOMXPath $dom, int $id, string $priority, int $recentCount): DOMElement
{
    $featured = $dom->query('//*[@id="latest-announcements"]//a[' . hmClass('home-announcement-card--featured') . ']');
    hmCheck($featured->length === 1 && hmLinkId($featured->item(0)) === $id, 'Exactly one featured card opens the selected highest-priority announcement.');
    $card = $featured->item(0);
    hmCheck($dom->query('.//*[' . hmClass('status-badge') . ']', $card)->length === 1 && str_contains($card->getAttribute('class'), 'home-announcement-card--' . strtolower($priority)), 'Featured badge and color tone reflect the real ' . $priority . ' priority.');
    hmCheck($dom->query('.//span[' . hmClass('home-announcement-cta') . ' and contains(., "Read Full Announcement")]', $card)->length === 1 && $dom->query('.//i[' . hmClass('bi-megaphone') . ']', $card)->length === 1, 'Featured card contains its announcement icon and readable full-announcement action.');
    hmCheck($dom->query('.//a|.//button|.//input|.//select|.//textarea', $card)->length === 0, 'The full-card link contains no nested interactive controls.');
    hmCheck($dom->query('//*[@id="latest-announcements"]//*[' . hmClass('home-announcements-recent') . ']/a[' . hmClass('home-announcement-card') . ']')->length === $recentCount, 'Recent column contains exactly the remaining available announcements.');
    hmCheck($dom->query('//*[@id="latest-announcements"]//*[' . hmClass('home-announcements-recent') . ']//a[' . hmClass('home-announcement-card--featured') . ']')->length === 0, 'Featured announcement is outside the recent list.');
    return $card;
}

function hmBrowser(string $directory, array $environment): void
{
    global $address;
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    hmCheck(is_file($chrome), 'Set SAGIPBRO_CHROME_BINARY for optional browser checks.');
    $script = <<<'JS'
const output = document.querySelector('pre'), frame = document.querySelector('iframe');
let checks = 0;
const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
const settle = () => new Promise(resolve => setTimeout(resolve, 160));
const load = url => new Promise(resolve => { frame.onload = resolve; frame.src = url; });
const overlap = (a, b) => a.left < b.right - 1 && a.right > b.left + 1 && a.top < b.bottom - 1 && a.bottom > b.top + 1;
(async () => {
    frame.style.width = '1440px'; frame.style.height = '900px';
    await load('/index.php'); await settle();
    const doc = frame.contentDocument, win = frame.contentWindow;
    const announcements = doc.querySelector('#latest-announcements');
    const cards = [...announcements.querySelectorAll('a.home-announcement-card')];
    const featured = announcements.querySelector('a.home-announcement-card--featured');
    const recent = announcements.querySelector('.home-announcements-recent');
    const featuredAction = featured.querySelector('.home-announcement-cta');
    const hero = doc.querySelector('.home-hero'), actions = [...hero.querySelectorAll('.hero-actions a')];
    const announcementJump = actions.find(action => action.getAttribute('href') === '#latest-announcements');
    const homepageUrl = win.location.href;
    const quick = doc.querySelector('[aria-labelledby="quick-info-title"]');
    const readiness = doc.querySelector('[aria-labelledby="snapshot-title"]');
    const inventory = readiness.querySelector('.surface-card'), centers = readiness.querySelector('.center-feature');
    check(doc.querySelector('link[rel="stylesheet"][href*="bootstrap"]').sheet !== null, 'Bootstrap stylesheet loaded');
    check(cards.length === 4 && cards.every(card => card.getAttribute('href').includes('view=')), 'One featured and three recent full-card announcement links');
    check(announcements.querySelectorAll('.home-announcement-card--featured').length === 1 && recent.querySelectorAll('a.home-announcement-card').length === 3 && cards[0] === featured, 'One leading feature is separate from the recent list');
    check(new Set(cards.map(card => card.href)).size === 4, 'Feature is never repeated among recent notices');
    check(featuredAction?.textContent.trim() === 'Read Full Announcement' && !featured.querySelector('a,button,input,select,textarea'), 'Featured action is readable without nested controls');
    featured.focus();
    check(doc.activeElement === featured, 'Featured full-card link is keyboard focusable');
    check(!win.homepageXss, 'Announcement HTML does not execute');
    check(hero.textContent.includes('SAGIPBRO') && actions.length === 3 && actions.some(action => action.getAttribute('href') === 'resources.php#resource-directory') && actions.some(action => action.getAttribute('href') === 'evacuation-centers.php#center-directory'), 'Original hero actions retained alongside the announcement jump');
    check(announcementJump?.textContent.trim() === 'View announcements', 'Hero exposes one clear homepage announcement action');
    check(quick.querySelectorAll('a.quick-card').length === 3, 'Three original Quick Information cards retained');
    check(doc.querySelector('.public-navbar') && doc.querySelector('footer'), 'Existing navbar/footer retained');
    check(announcements.previousElementSibling?.classList.contains('emergency-band') && announcements.nextElementSibling === quick && announcements.offsetTop < quick.offsetTop && quick.offsetTop < readiness.offsetTop, 'Featured section immediately follows advisory and precedes Quick Information');
    for (const width of [1440, 1024, 900, 768, 390, 320]) {
        frame.style.width = width + 'px'; win.history.replaceState(null, '', homepageUrl); win.scrollTo(0, 0); await settle();
        check(win.innerWidth === width, width + 'px test uses the actual requested CSS viewport');
        check(doc.documentElement.scrollWidth <= width + 1, width + 'px no horizontal overflow');
        const h = hero.getBoundingClientRect();
        for (const action of actions) {
            const rect = action.getBoundingClientRect();
            check(rect.left >= h.left && rect.right <= h.right + 1 && rect.height >= 40, width + 'px hero action fits and remains tappable');
        }
        for (let i = 0; i < actions.length; i++) for (let j = i + 1; j < actions.length; j++) check(!overlap(actions[i].getBoundingClientRect(), actions[j].getBoundingClientRect()), width + 'px hero actions remain non-overlapping');
        for (let i = 0; i < cards.length; i++) {
            const rect = cards[i].getBoundingClientRect();
            check(rect.left >= 0 && rect.right <= width + 1 && rect.width >= 200, width + 'px announcement card fits');
            check(cards[i].scrollWidth <= cards[i].clientWidth + 1, width + 'px long announcement text stays within its card');
            const time = cards[i].querySelector('time');
            check(time && /\d:\d{2} [AP]M PHT/.test(time.textContent) && time.getAttribute('datetime').endsWith('+08:00'), width + 'px card retains publication date and local time');
            for (let j = i + 1; j < cards.length; j++) check(!overlap(rect, cards[j].getBoundingClientRect()), width + 'px cards do not overlap');
        }
        const featureRect = featured.getBoundingClientRect(), recentRect = recent.getBoundingClientRect();
        const actionRect = featuredAction.getBoundingClientRect();
        check(actionRect.height >= 40 && actionRect.width >= 120 && actionRect.left >= featureRect.left && actionRect.right <= featureRect.right + 1 && actionRect.bottom <= featureRect.bottom + 1 && parseFloat(win.getComputedStyle(featuredAction).fontSize) >= 14, width + 'px featured action is readable, tappable and unclipped');
        if (width >= 768) {
            const expectedRatio = width >= 992 ? .65 : .58;
            check(Math.abs(featureRect.width / (featureRect.width + recentRect.width) - expectedRatio) < .025, width + 'px feature/recent column ratio');
            check(recentRect.left >= featureRect.right - 1 && Math.abs(recentRect.top - featureRect.top) < 2, width + 'px columns align at their top edge');
            check([...recent.querySelectorAll('a')].every(card => featureRect.width > card.getBoundingClientRect().width && featureRect.height >= card.getBoundingClientRect().height), width + 'px feature is larger than each compact recent card');
        } else {
            check(recentRect.top >= featureRect.bottom - 1 && Math.abs(recentRect.width - featureRect.width) < 2, width + 'px feature appears first in the single-column layout');
        }
        const inv = inventory.getBoundingClientRect(), cen = centers.getBoundingClientRect();
        check(width >= 992 ? cen.left >= inv.right - 1 : (width >= 768 || cen.top >= inv.bottom - 1), width + 'px readiness desktop/mobile columns');
        check(!overlap(inv, cen), width + 'px readiness cards do not overlap');
        if (width < 768) check(cards[1].getBoundingClientRect().top >= cards[0].getBoundingClientRect().bottom - 1, width + 'px announcements stack vertically');
        announcementJump.click(); await settle();
        check(frame.contentDocument === doc && win.location.href.split('#')[0] === homepageUrl.split('#')[0] && win.location.hash === '#latest-announcements', width + 'px announcement action selects the matching homepage fragment without redirecting');
    }
    const selectedId = new URL(cards[0].href).searchParams.get('view');
    const selectedTitle = cards[0].querySelector('h3,h2').textContent;
    await load(cards[0].href); await settle();
    const detailDoc = frame.contentDocument;
    const selected = detailDoc.querySelector('#announcement-' + selectedId);
    check(selected && selected.textContent.includes(selectedTitle), 'Clicking homepage announcement opens exact selected full details');
    check(selected.textContent.includes('HOME_FULL_BODY_END') && !frame.contentWindow.homepageXss, 'Selected detail displays escaped complete description');
    const back = [...detailDoc.querySelectorAll('a')].find(a => /Back to announcements/i.test(a.textContent));
    check(back && !new URL(back.href).searchParams.has('view'), 'Back returns to directory without selected view');
    output.textContent = JSON.stringify({ok: true, checks});
})().catch(error => { output.textContent = JSON.stringify({ok: false, checks, error: error.message}); });
JS;
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe title="Homepage"></iframe><script>' . $script . '</script>');
    $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=15000', '--dump-dom', $address . '/__homepage_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    hmCheck(is_resource($process), 'Chrome must start.'); fclose($pipes[0]);
    $deadline = microtime(true) + 45;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($process)['running']) proc_terminate($process);
    proc_close($process);
    preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/dom.html'), $match);
    $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    hmCheck(($result['ok'] ?? false) === true, 'Browser assertions: ' . json_encode($result));
    fwrite(STDOUT, 'PASS: ' . $result['checks'] . " desktop/tablet/mobile Chrome assertions.\n");
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    hmCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Valid MySQL connection configuration.');
    $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    hmCheck((bool) preg_match('/\Asagipbro_home_[a-f0-9]{20}\z/', $name), 'Safe private fixture database identifier.');
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $db->exec('USE `' . $name . '`'); $db->exec("SET time_zone = '+08:00'");
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) {
        $sql = trim($sql); if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE|USE)\b/i', $sql)) $db->exec($sql);
    }
    migratePublicServices($db);
    $staff = hmInsert($db, 'users', ['full_name' => 'Private Homepage Fixture', 'username' => 'homepage_test', 'role' => 'admin', 'password_hash' => 'TEST-NOT-LOGIN']);
    hmInsert($db, 'resources', ['name' => 'HOME Water', 'category' => 'Water', 'unit' => 'bottles', 'stock' => 37, 'low_stock_threshold' => 7]);
    hmInsert($db, 'resources', ['name' => 'HOME Rice', 'category' => 'Food', 'unit' => 'packs', 'stock' => 0, 'low_stock_threshold' => 5]);
    hmInsert($db, 'resources', ['name' => 'HOME Inactive', 'category' => 'Food', 'unit' => 'packs', 'stock' => 555, 'status' => 'Inactive']);
    hmInsert($db, 'evacuation_centers', ['name' => 'HOME Open', 'address' => 'Bonuan Binloc', 'capacity' => 100, 'occupants' => 22, 'status' => 'Open']);
    hmInsert($db, 'evacuation_centers', ['name' => 'HOME Full', 'address' => 'Bonuan Binloc', 'capacity' => 100, 'occupants' => 100, 'status' => 'Full']);
    hmInsert($db, 'evacuation_centers', ['name' => 'HOME Closed', 'address' => 'Bonuan Binloc', 'capacity' => 200, 'occupants' => 5, 'status' => 'Closed']);
    hmCheck(mkdir($directory, 0700), 'Private temporary directory created.');
    $environment = getenv();
    $environment['SAGIPBRO_DB_HOST'] = $host; $environment['SAGIPBRO_DB_PORT'] = $port;
    $environment['SAGIPBRO_DB_USER'] = $user === false ? 'root' : $user;
    $environment['SAGIPBRO_DB_PASSWORD'] = $password === false ? '' : $password;
    $environment['SAGIPBRO_DB_NAME'] = $name; $environment['SAGIPBRO_BASE_URL'] = '';
    $environment['SAGIPBRO_SESSION_PATH'] = $directory;
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $listen = stream_socket_get_name($socket, false); fclose($socket); $address = 'http://' . $listen;
    $router = '<?php $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($path === "/__homepage_browser") { readfile(' . var_export($directory . '/browser.html', true) . '); return true; } if (in_array($path, ["/index.php", "/announcements.php"], true)) { ob_start(); require ' . var_export($project, true) . ' . $path; header_remove("X-Frame-Options"); ob_end_flush(); return true; } return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    hmCheck(is_resource($server), 'Private PHP HTTP server starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do { $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(25000); } while (microtime(true) < $deadline);

    hmFeed($db, [], null);
    $empty = hmGet('index.php'); $html = $empty['body']; $dom = hmDom($html);
    hmCheck(count(hmCards($dom)) === 0, 'Empty database has no fabricated announcement cards.');
    hmCheck($dom->query('//*[@id="latest-announcements"]')->length === 1 && preg_match('/no (?:current |published |active )?announcements/i', $dom->query('//*[@id="latest-announcements"]')->item(0)->textContent) === 1, 'Professional empty announcement state is shown.');
    hmCheck(!str_contains($html, 'emergency-band--urgent') && str_contains($html, 'Community preparedness reminder'), 'No urgent notice preserves original general preparedness reminder.');
    hmCheck(str_contains(strtolower($empty['headers']), 'cache-control: no-store'), 'Homepage always refreshes current database publication state.');
    hmPreserved($html);

    $base = ['body' => "Full instruction & safety guidance.\n<script>window.homepageXss=true</script>\n" . str_repeat('Follow verified barangay guidance. ', 20) . 'HOME_FULL_BODY_END', 'created_by' => $staff, 'status' => 'Published', 'published_at' => '2020-01-01 12:00:00'];
    $urgentOld = hmInsert($db, 'announcements', ['title' => 'HOME Older urgent', 'priority' => 'Urgent'] + $base);
    hmFeed($db, [$urgentOld], $urgentOld);
    $single = hmDom(hmGet('index.php')['body']);
    hmFeatured($single, $urgentOld, 'Urgent', 0);
    hmCheck(count(hmCards($single)) === 1 && $single->query('//*[' . hmClass('home-announcements-grid--single') . ']')->length === 1, 'A single announcement produces one full-width feature without placeholders or duplication.');
    $urgentNew = hmInsert($db, 'announcements', ['title' => 'HOME Latest urgent <img src=x onerror=window.homepageXss=true>', 'priority' => 'Urgent', 'published_at' => '2022-01-01 12:00:00'] + $base);
    $high = hmInsert($db, 'announcements', ['title' => 'HOME Latest high', 'priority' => 'High', 'published_at' => '2025-01-01 12:00:00'] + $base);
    $other = [];
    foreach (['Medium', 'Low', 'Normal'] as $i => $priority) $other[] = hmInsert($db, 'announcements', ['title' => 'HOME ' . $priority . ' notice', 'priority' => $priority, 'published_at' => '2024-01-0' . ($i + 1) . ' 12:00:00'] + $base);
    $old = hmInsert($db, 'announcements', ['title' => 'HOME Old selected notice beyond first page', 'priority' => 'Low', 'published_at' => '2010-01-01 00:00:00'] + $base);
    $private = [];
    foreach ([['Draft', '2020-01-01 00:00:00', null], ['Archived', '2020-01-01 00:00:00', null], ['Published', '2099-01-01 00:00:00', null], ['Published', '2020-01-01 00:00:00', '2021-01-01 00:00:00'], ['Published', null, null]] as $i => [$status, $published, $expires]) {
        $private[] = hmInsert($db, 'announcements', ['title' => 'HOME_PRIVATE_' . $i, 'body' => 'HOME_PRIVATE_BODY', 'priority' => 'Urgent', 'status' => $status, 'published_at' => $published, 'expires_at' => $expires] + $base);
    }
    hmFeed($db, [$urgentNew, $urgentOld, $high, $other[2]], $urgentNew);
    $html = hmGet('index.php')['body']; $dom = hmDom($html); $cards = hmCards($dom);
    hmCheck(array_map('hmLinkId', $cards) === [$urgentNew, $urgentOld, $high, $other[2]], 'Homepage displays the urgent feature plus three distinct urgent-first recent publications, not a second priority-ranked list.');
    $featured = hmFeatured($dom, $urgentNew, 'Urgent', 3);
    $featuredPreview = $dom->query('.//*[' . hmClass('home-announcement-preview') . ']', $featured)->item(0)->textContent;
    hmCheck(preg_match_all('/./us', $featuredPreview) > 140 && preg_match_all('/./us', $featuredPreview) <= 320 && !str_contains($featuredPreview, 'HOME_FULL_BODY_END'), 'Featured description uses the larger 320-character escaped excerpt rather than the complete body.');
    hmCheck($dom->query('//*[@id="latest-announcements"]//a[' . hmClass('home-announcement-card--urgent') . ']')->length === 2, 'Urgent cards carry the subtle red-border class.');
    foreach ($cards as $i => $card) {
        $id = hmLinkId($card);
        hmCheck(parse_url($card->getAttribute('href'), PHP_URL_FRAGMENT) === 'announcement-' . $id, 'Announcement card link targets exact selected detail.');
        hmCheck($dom->query('.//time', $card)->length === 1 && $dom->query('.//*[' . hmClass('status-badge') . ']', $card)->length === 1, 'Every card shows its publication date and priority.');
        $time = $dom->query('.//time', $card)->item(0);
        hmCheck(preg_match('/\d:\d{2} [AP]M PHT/', $time->textContent) === 1 && str_ends_with($time->getAttribute('datetime'), '+08:00'), 'Card date includes readable publication time with its Philippine timezone.');
        hmCheck($dom->query('//*[@id="' . $card->getAttribute('aria-labelledby') . '"]')->length === 1, 'Every full-card link has a unique visible heading as its accessible name.');
        if ($i > 0) hmCheck(preg_match_all('/./us', $dom->query('.//*[' . hmClass('home-announcement-preview') . ']', $card)->item(0)->textContent) <= 140, 'Recent notices use compact previews instead of full descriptions.');
    }
    $bar = $dom->query('//section[' . hmClass('emergency-band--urgent') . ']')->item(0);
    hmCheck($bar instanceof DOMElement && str_contains($bar->textContent, 'HOME Latest urgent'), 'Advisory shows the latest active urgent title.');
    hmCheck(!str_contains($bar->textContent, 'Full instruction') && !str_contains($bar->textContent, 'Follow verified barangay'), 'Urgent strip contains the featured title without duplicating its body excerpt.');
    $guidance = $dom->query('.//a', $bar)->item(0);
    hmCheck($guidance instanceof DOMElement && hmLinkId($guidance) === $urgentNew, 'Read Guidance opens the exact urgent announcement.');
    hmCheck(!str_contains($html, '<script>window.homepageXss') && !str_contains($html, '<img src=x') && str_contains($html, '&lt;img'), 'Database titles and descriptions are escaped.');
    hmCheck(strpos($html, 'emergency-band--urgent') < strpos($html, 'id="latest-announcements"') && strpos($html, 'id="latest-announcements"') < strpos($html, 'id="quick-info-title"'), 'Featured announcements are immediately discoverable after the urgent advisory and before Quick Information.');
    hmPreserved($html);
    $cardIds = array_map('hmLinkId', $cards);
    hmCheck(count($cardIds) === count(array_unique($cardIds)), 'Homepage announcement feed does not duplicate cards.');
    foreach (array_merge($cards, [$guidance]) as $link) {
        $selected = hmDom(hmGet($link->getAttribute('href'))['body']);
        $article = $selected->query('//article[@id="announcement-' . hmLinkId($link) . '"]')->item(0);
        hmCheck($article instanceof DOMElement && str_contains($article->textContent, 'HOME_FULL_BODY_END'), 'Every homepage card/guidance link opens its complete selected instruction.');
    }
    $selectedQuery = ['view' => $old, 'q' => 'UNRELATED filter state & %', 'priority' => 'Urgent', 'page' => 2, 'per_page' => 6];
    $selected = hmDom(hmGet('announcements.php?' . http_build_query($selectedQuery))['body']);
    hmCheck($selected->query('//article[@id="announcement-' . $old . '"]')->length === 1, 'Old selected notice is accessible beyond the first six directory records.');
    $back = $selected->query('//a[contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "back to announcements")]')->item(0);
    hmCheck($back instanceof DOMElement, 'Selected detail offers Back to Announcements.');
    parse_str((string) parse_url($back->getAttribute('href'), PHP_URL_QUERY), $backQuery);
    hmCheck(!array_key_exists('view', $backQuery), 'Back removes selected view from the directory URL.');
    foreach (['q', 'priority', 'page', 'per_page'] as $key) hmCheck((string) ($backQuery[$key] ?? '') === (string) $selectedQuery[$key], 'Back retains directory state: ' . $key);
    foreach (array_merge($private, [99999999, -1, 'invalid', '1 OR 1=1']) as $view) hmGet('announcements.php?' . http_build_query(['view' => $view]), 404);
    hmGet('announcements.php?' . http_build_query(['view' => ['bad']]), 404);
    $directoryDom = hmDom(hmGet('announcements.php')['body']);
    hmCheck($directoryDom->query('//*[@id="announcement-directory"]//article')->length === 6, 'Full directory keeps its existing six-record pagination.');
    $next = $directoryDom->query('//a[@rel="next"]')->item(0);
    hmCheck($next instanceof DOMElement, 'Full directory exposes a working Next link when seven public notices exist.');
    $pageTwo = hmDom(hmGet($next->getAttribute('href'))['body']);
    hmCheck($pageTwo->query('//*[@id="announcement-directory"]//article')->length === 1 && str_contains($pageTwo->query('//*[@id="announcement-directory"]')->item(0)->textContent, 'HOME Old selected notice beyond first page'), 'Next loads the seventh public notice without duplicating page one.');
    $previous = $pageTwo->query('//a[@rel="prev"]')->item(0);
    hmCheck($previous instanceof DOMElement && hmDom(hmGet($previous->getAttribute('href'))['body'])->query('//*[@id="announcement-directory"]//article')->length === 6, 'Previous returns to the first six public notices.');
    foreach (['Urgent' => 2, 'High' => 1, 'Medium' => 1, 'Low' => 2, 'Normal' => 1] as $priority => $total) {
        $filter = hmDom(hmGet('announcements.php?' . http_build_query(['priority' => $priority, 'q' => 'HOME']))['body']);
        hmCheck($filter->query('//*[@id="announcement-directory"]//article')->length === $total, 'Priority/search filters retained: ' . $priority);
        hmCheck($filter->query('//*[contains(@class,"public-pagination")]')->length >= 1, 'Pagination retained with priority ' . $priority);
    }
    if (getenv('SAGIPBRO_HOMEPAGE_BROWSER') === '1') hmBrowser($directory, $environment);

    $db->exec('UPDATE announcements SET status = \'Archived\' WHERE id = ' . $urgentNew);
    hmFeed($db, [$urgentOld, $high, $other[2], $other[1]], $urgentOld);
    $html = hmGet('index.php')['body'];
    hmCheck(!str_contains($html, 'HOME Latest urgent') && str_contains($html, 'HOME Older urgent'), 'Refresh removes archived urgent notice and promotes the next active urgent.');
    $db->exec('DELETE FROM announcements WHERE id = ' . $urgentOld);
    $db->exec("UPDATE announcements SET published_at = '2025-12-01 12:00:00' WHERE id = " . $other[2]);
    $db->exec("UPDATE announcements SET published_at = '2025-11-01 12:00:00' WHERE id = " . $other[1]);
    hmFeed($db, [$high, $other[2], $other[1], $other[0]], null);
    $html = hmGet('index.php')['body'];
    hmFeatured(hmDom($html), $high, 'High', 3);
    hmCheck(!str_contains($html, 'emergency-band--urgent') && str_contains($html, 'Community preparedness reminder'), 'Deleting last active urgent restores general advisory fallback.');
    hmCheck(str_contains($html, 'HOME Normal notice'), 'Recent notices remain publication-date ordered even when lower-priority notices are newer than the feature.');
    $tie = hmInsert($db, 'announcements', ['title' => 'HOME Same-date high tie', 'priority' => 'High', 'published_at' => '2025-01-01 12:00:00'] + $base);
    hmFeed($db, [$tie, $other[2], $other[1], $high], null);
    $db->exec("UPDATE announcements SET published_at = '2024-12-01 12:00:00' WHERE id = " . $tie);
    hmFeed($db, [$high, $other[2], $other[1], $tie], null);
    $db->exec('DELETE FROM announcements WHERE id = ' . $tie);
    $db->exec("UPDATE announcements SET title = 'HOME Updated high notice' WHERE id = " . $high);
    $html = hmGet('index.php')['body'];
    hmCheck(str_contains($html, 'HOME Updated high notice') && !str_contains($html, 'HOME Latest high'), 'Refresh reflects administrator edits.');
    $db->exec("UPDATE announcements SET expires_at = '2020-01-01 00:00:00' WHERE id = " . $high);
    $html = hmGet('index.php')['body'];
    hmCheck(!str_contains($html, 'HOME Updated high notice'), 'Refresh removes newly expired publications.');
    hmFeed($db, [$other[0], $other[2], $other[1], $old], null);
    hmFeatured(hmDom($html), $other[0], 'Medium', 3);
    $db->exec("UPDATE announcements SET status = 'Archived' WHERE id = " . $other[0]);
    hmFeed($db, [$other[1], $other[2], $old], null);
    hmFeatured(hmDom(hmGet('index.php')['body']), $other[1], 'Low', 2);
    $db->exec("UPDATE announcements SET status = 'Archived' WHERE id IN (" . $other[1] . ',' . $old . ')');
    hmFeed($db, [$other[2]], null);
    hmFeatured(hmDom(hmGet('index.php')['body']), $other[2], 'Normal', 0);
    $db->exec("UPDATE announcements SET status = 'Published' WHERE id IN (" . implode(',', [$other[0], $other[1], $old]) . ')');
    $new = hmInsert($db, 'announcements', ['title' => 'HOME Fresh public notice', 'priority' => 'High', 'published_at' => '2025-06-01 12:00:00'] + $base);
    hmFeed($db, [$new, $other[2], $other[1], $other[0]], null);
    hmCheck(str_contains(hmGet('index.php')['body'], 'HOME Fresh public notice'), 'Refresh reflects newly published administrator records.');

    $db->exec('ALTER TABLE announcements CHANGE body content TEXT NOT NULL');
    $legacy = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $legacy->exec("SET time_zone = '+08:00'");
    $legacyFeed = publicHomepageAnnouncements($legacy);
    hmCheck((int) $legacyFeed['rows'][0]['id'] === $new && $legacyFeed['urgent'] === null, 'Legacy content-column schema supports the shared homepage feed.');
    $html = hmGet('index.php')['body']; hmPreserved($html);
    hmCheck(str_contains($html, 'HOME Fresh public notice'), 'Legacy schema is supported over real HTTP.');
    $db->exec('ALTER TABLE announcements DROP priority');
    $html = hmGet('index.php')['body']; $dom = hmDom($html);
    hmCheck(count(hmCards($dom)) === 0 && preg_match('/(?:updates|announcements|information).*?(?:unavailable|could not|cannot)/is', $dom->query('//*[@id="latest-announcements"]')->item(0)->textContent) === 1, 'Announcement query failure displays an honest unavailable state, not a false empty state.');
    hmPreserved($html);
    hmCheck(str_contains($html, 'Community preparedness reminder'), 'Announcement query failure preserves general preparedness guidance.');
    $db->exec('DROP TABLE announcements');
    $outage = hmGet('index.php', 503);
    hmCheck(str_contains($outage['body'], 'Information temporarily unavailable') && str_contains($outage['body'], 'Community preparedness reminder'), 'Database outage remains honest and preserves useful general guidance.');
    hmCheck(count(hmCards(hmDom($outage['body']))) === 0, 'Database outage never fabricates live announcement cards.');
    fwrite(STDOUT, "PASS: real-record visibility, featured priority/date/id selection, three unique recent notices, urgent title advisory, escaping, links, filters/pagination, mutations, legacy schema, preservation, single/empty/outage states.\n");
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($server)) { if (proc_get_status($server)['running']) proc_terminate($server); proc_close($server); }
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_home_[a-f0-9]{20}\z/', $name)) {
        try { $db->exec('DROP DATABASE `' . $name . '`'); } catch (Throwable $error) { $failed = true; fwrite(STDERR, 'Cleanup pending for private fixture database ' . $name . ".\n"); }
    }
    $resolved = realpath($directory); $temp = realpath(sys_get_temp_dir());
    if ($resolved && $temp && str_starts_with(strtolower($resolved), strtolower($temp . DIRECTORY_SEPARATOR)) && preg_match('/\Asagipbro-home-[a-f0-9]{16}\z/', basename($resolved))) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname()); else @unlink($entry->getPathname()); }
        @rmdir($resolved);
    }
    fwrite(STDOUT, "Cleaned up isolated fixtures; application records were untouched.\n");
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED: ' : 'PASSED: ') . $checks . " homepage assertions.\n");
exit($failed ? 1 : 0);
