<?php
declare(strict_types=1);

/** CLI-only isolated MySQL/HTTP regression checks. Optional Chrome: SAGIPBRO_PANEL_BROWSER=1. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../database/public_services_migration.php';
require_once __DIR__ . '/../includes/public_data.php';
$project = dirname(__DIR__);
$name = 'sagipbro_panel_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-panel-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $checks = 0; $failed = false;

function apCheck(bool $condition, string $message): void {
    global $checks; ++$checks; if (!$condition) throw new LogicException($message);
}
function apInsert(PDO $db, string $table, array $values): int {
    $statement = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values)); return (int) $db->lastInsertId();
}
function apRequest(string $route, string $method = 'GET', ?array $data = null, int $expected = 200): string {
    global $address, $cookie, $csrf;
    $headers = ['Cookie: ' . $cookie, 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];
    $stream = @fopen($address . '/' . $route, 'rb', false, stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data), 'timeout' => 10, 'ignore_errors' => true]]));
    if (!$stream) throw new LogicException('HTTP server unavailable.');
    $body = (string) stream_get_contents($stream); $meta = stream_get_meta_data($stream); fclose($stream);
    apCheck(str_contains($meta['wrapper_data'][0] ?? '', ' ' . $expected . ' '), $route . ' HTTP status must be ' . $expected . ': ' . $body);
    return $body;
}
function apDom(string $body): DOMXPath {
    $doc = new DOMDocument(); $old = libxml_use_internal_errors(true); $doc->loadHTML('<?xml encoding="UTF-8">' . $body); libxml_clear_errors(); libxml_use_internal_errors($old); return new DOMXPath($doc);
}
function apBrowser(string $directory, array $environment, bool $emptyState = false): void {
    global $address;
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    apCheck(is_file($chrome), 'Set SAGIPBRO_CHROME_BINARY to run Chrome checks.');
    $script = <<<'JS'
const output = document.querySelector('pre'), frame = document.querySelector('iframe');
let checks = 0;
const check = (value, message) => { checks++; if (!value) throw new Error(message); };
const settle = () => new Promise(resolve => setTimeout(resolve, 150));
const intersects = (a, b) => a.left < b.right - 1 && a.right > b.left + 1 && a.top < b.bottom - 1 && a.bottom > b.top + 1;
(async () => {
    frame.style.width = '1440px'; frame.style.height = '800px';
    await new Promise(resolve => { frame.onload = resolve; frame.src = '/services.php'; });
    const doc = frame.contentDocument, win = frame.contentWindow;
    const main = doc.querySelector('.public-services-main'), panel = doc.querySelector('[data-announcements-panel]');
    const card = panel.querySelector('.public-announcements-panel'), scroller = panel.querySelector('[data-announcements-content]');
    const header = panel.querySelector('.announcements-panel-header'), panelFooter = panel.querySelector('.announcements-panel-footer');
    const list = panel.querySelector('[data-announcements-list]'), detail = panel.querySelector('[data-announcements-detail]');
    const summary = list?.querySelector('summary'), full = list?.querySelector('[data-announcement-full]');
    const navbar = doc.querySelector('.public-navbar'), cta = doc.querySelector('[aria-labelledby="services-cta-title"]'), footer = doc.querySelector('.public-footer');
    const viewAll = [...panel.querySelectorAll('a')].find(link => /View all announcements/i.test(link.textContent));
    check(doc.querySelector('link[rel="stylesheet"][href*="bootstrap"]').sheet !== null, 'Bootstrap loaded');
    check(doc.body.classList.contains('services-page'), 'Services-only body scope is applied');
    check(doc.querySelector('#services-page-title').textContent === 'Disaster Relief Services', 'Requested Services title is retained');
    check(viewAll && viewAll.getAttribute('href').includes('announcements.php'), 'View All Announcements remains linked');
    check(emptyState ? !list && panel.textContent.includes('No current announcements') : list.querySelectorAll('summary').length === 5, 'Expected real database populated/empty state is rendered');
    doc.documentElement.style.scrollBehavior = 'auto';
    const originalUrl = win.location.href, geometry = [];
    const checkFrame = (width, label) => {
        const cardBounds = card.getBoundingClientRect(), serviceCards = main.querySelectorAll('.service-row');
        const first = serviceCards[0].getBoundingClientRect(), last = serviceCards[serviceCards.length - 1].getBoundingClientRect();
        if (width >= 768) {
            check(Math.abs(cardBounds.top - first.top) < 2 && cardBounds.bottom <= last.bottom + 2, width + 'px ' + label + ' panel aligns with Relief Resources and stays within the service column');
            check(cardBounds.height < main.getBoundingClientRect().height - 1, width + 'px ' + label + ' panel stays compact instead of stretching to Reports');
            check(win.getComputedStyle(card).position !== 'absolute', width + 'px ' + label + ' panel uses natural height rather than absolute height matching');
            const rem = Number.parseFloat(win.getComputedStyle(doc.documentElement).fontSize);
            const viewportLimit = win.innerHeight - navbar.getBoundingClientRect().height - rem * 2;
            check(cardBounds.height <= Math.min(30 * rem, viewportLimit) + 2, width + 'px ' + label + ' panel respects the compact/viewport height limit');
        }
        const h = header.getBoundingClientRect(), f = panelFooter.getBoundingClientRect();
        check(h.top >= cardBounds.top - 1 && h.bottom <= cardBounds.bottom + 1, width + 'px ' + label + ' header remains inside its card');
        check(f.top >= cardBounds.top - 1 && f.bottom <= cardBounds.bottom + 1 && f.top >= h.bottom, width + 'px ' + label + ' View All/footer remains inside its card');
        check(!intersects(cardBounds, cta.getBoundingClientRect()) && !intersects(cardBounds, footer.getBoundingClientRect()), width + 'px ' + label + ' does not overlap CTA/footer');
    };
    for (const width of [1440, 1280, 1024, 900, 768, 390, 320]) {
        frame.style.width = width + 'px'; win.scrollTo(0, 0); await settle();
        const a = panel.getBoundingClientRect(), m = main.getBoundingClientRect();
        check(win.innerWidth === width, width + 'px actual CSS viewport is tested');
        check(doc.documentElement.scrollWidth <= width + 1, width + 'px has no horizontal overflow');
        check(width >= 768 ? a.left >= m.right : a.bottom <= m.top, width + 'px correct columns/mobile-above order');
        check(win.getComputedStyle(panel).position === 'static', width + 'px announcement card uses normal document flow');
        if (width >= 992) {
            check(Math.abs(m.width / (m.width + a.width) - .7) < .025, width + 'px desktop retains 70/30 columns');
            const heroHeight = doc.querySelector('.services-hero').getBoundingClientRect().height;
            const firstCardHeight = main.querySelector('.service-row').getBoundingClientRect().height;
            const baselineCard = width >= 1280 ? 215.0625 : 238.828125;
            check(heroHeight >= 392 * .7 && heroHeight <= 392 * .8, width + 'px compact hero remains 20–30% shorter');
            check(firstCardHeight >= baselineCard * .74 && firstCardHeight <= baselineCard * .81, width + 'px compact service cards retain their approximate 20–25% reduction');
        }
        if (emptyState) check(card.getBoundingClientRect().height < m.height, width + 'px empty panel has natural compact height');
        checkFrame(width, 'list');
        const documentTop = panel.getBoundingClientRect().top + win.scrollY;
        win.scrollTo(0, Math.max(0, documentTop - 180)); await settle();
        const firstTop = panel.getBoundingClientRect().top, firstMainTop = main.getBoundingClientRect().top, firstScroll = win.scrollY;
        win.scrollBy(0, 120); await settle();
        const delta = win.scrollY - firstScroll;
        check(delta > 2 && Math.abs(panel.getBoundingClientRect().top - firstTop + delta) < 2, width + 'px announcement card scrolls with the page without following the viewport');
        check(Math.abs(main.getBoundingClientRect().top - firstMainTop + delta) < 2, width + 'px service column keeps its normal document flow');
        check(Math.abs(panel.getBoundingClientRect().top + win.scrollY - documentTop) < 2, width + 'px scrolling preserves the announcement card document position');
        checkFrame(width, 'page scroll');
        win.scrollTo(0, documentTop + m.height); await settle();
        checkFrame(width, 'end of service column');
        viewAll.scrollIntoView({block: 'center', behavior: 'auto'}); await settle();
        const allBounds = viewAll.getBoundingClientRect();
        check(allBounds.top >= navbar.getBoundingClientRect().bottom - 1 && allBounds.bottom <= win.innerHeight + 1, width + 'px View All is reachable below the navbar');
        win.scrollTo(0, 0); await settle();
        const serviceCards = [...main.querySelectorAll('.service-row')];
        for (let index = 0; index < serviceCards.length; index++) {
            const bounds = serviceCards[index].getBoundingClientRect();
            check(bounds.left >= 0 && bounds.right <= width + 1 && serviceCards[index].querySelector('a').getBoundingClientRect().height >= 44, width + 'px service card ' + index + ' fits and is tappable');
            if (index) check(bounds.top >= serviceCards[index - 1].getBoundingClientRect().bottom - 1, width + 'px service cards remain non-overlapping');
        }
        geometry.push({viewport: width, mainHeight: m.height, announcementHeight: card.getBoundingClientRect().height, empty: emptyState});
        if (emptyState) continue;
        const originalHeight = card.getBoundingClientRect().height, originalMainHeight = main.getBoundingClientRect().height;
        summary.click(); await settle();
        check(list.hidden && !detail.hidden, width + 'px click opens in-panel details');
        check(detail.querySelector('h3').textContent === full.querySelector('h3').textContent, width + 'px selected title retained');
        check(detail.querySelector('.public-record-details').textContent === full.querySelector('.public-record-details').textContent, width + 'px complete huge description retained');
        check(detail.textContent.includes('Published') && detail.textContent.includes('Urgent'), width + 'px priority/publication status retained');
        check(win.location.href === originalUrl && !doc.querySelector('.modal.show'), width + 'px no redirect/modal/drawer');
        checkFrame(width, 'detail');
        if (width >= 768) {
            check(Math.abs(card.getBoundingClientRect().height - originalHeight) < 2 && Math.abs(main.getBoundingClientRect().height - originalMainHeight) < 2, width + 'px opening huge details preserves compact card and service-column heights');
            check(scroller.scrollHeight > scroller.clientHeight + 100 && ['auto', 'scroll'].includes(win.getComputedStyle(scroller).overflowY), width + 'px huge description uses internal scrolling');
            scroller.scrollTop = scroller.scrollHeight; await settle();
            check(scroller.scrollTop > 100, width + 'px full description can be scrolled to its end');
            checkFrame(width, 'scrolled detail');
        }
        panel.querySelector('[data-announcements-back]').click(); await settle();
        check(!list.hidden && detail.hidden && doc.activeElement === summary, width + 'px Back restores list and selected focus');
        if (width >= 768) check(Math.abs(card.getBoundingClientRect().height - originalHeight) < 2, width + 'px Back preserves the compact card height');
        checkFrame(width, 'Back');
        const notices = list.querySelectorAll('summary'), lowerNotice = notices[notices.length - 1];
        lowerNotice.scrollIntoView({block: 'center', behavior: 'auto'}); await settle();
        const listScroll = win.scrollY, noticeTop = lowerNotice.getBoundingClientRect().top;
        lowerNotice.click(); await settle();
        const titleBounds = detail.querySelector('h3').getBoundingClientRect();
        const backButton = panel.querySelector('[data-announcements-back]'), backBounds = backButton.getBoundingClientRect();
        check(titleBounds.top >= navbar.getBoundingClientRect().bottom - 1 && titleBounds.bottom <= win.innerHeight, width + 'px lower notice title is revealed below navbar');
        check(backBounds.top >= navbar.getBoundingClientRect().bottom - 1 && backBounds.bottom <= win.innerHeight, width + 'px lower notice Back is revealed below navbar');
        backButton.click(); await settle();
        check(doc.activeElement === lowerNotice && !list.hidden && detail.hidden, width + 'px lower notice Back restores focus');
        check(Math.abs(win.scrollY - listScroll) < 2 && Math.abs(lowerNotice.getBoundingClientRect().top - noticeTop) < 2, width + 'px lower notice Back restores viewport');
    }
    if (!emptyState) {
        frame.style.width = '1440px'; win.scrollTo(0, 0); await settle();
        for (const notice of list.querySelectorAll('summary')) {
            const expected = notice.parentElement.querySelector('[data-announcement-full]');
            notice.click(); await settle();
            check(detail.textContent.includes(expected.querySelector('h3').textContent), 'Every notice opens its selected title');
            for (const badge of expected.querySelectorAll('.status-badge')) check(detail.textContent.includes(badge.textContent), 'Every notice retains priority/status ' + badge.textContent);
            panel.querySelector('[data-announcements-back]').click(); await settle();
            check(!list.hidden && detail.hidden && doc.activeElement === notice, 'Every notice returns through Back with focus preserved');
        }
    }
    output.textContent = JSON.stringify({ok: true, checks, geometry});
})().catch(error => { output.textContent = JSON.stringify({ok: false, checks, error: error.message}); });
JS;
    $script = 'const emptyState = ' . ($emptyState ? 'true' : 'false') . ";\n" . $script;
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe title="Services"></iframe><script>' . $script . '</script>');
    $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=30000', '--dump-dom', $address . '/__panel_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    apCheck(is_resource($process), 'Chrome must start.'); fclose($pipes[0]);
    $deadline = microtime(true) + 45;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($process)['running']) proc_terminate($process); proc_close($process);
    preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/dom.html'), $match);
    $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    apCheck(($result['ok'] ?? false) === true, 'Browser checks: ' . json_encode($result));
    fwrite(STDOUT, 'PASS: ' . $result['checks'] . " desktop/tablet/mobile Chrome assertions.\n");
    fwrite(STDOUT, json_encode($result['geometry'] ?? []) . "\n");
}
try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    apCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Valid server configuration.');
    $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', getenv('SAGIPBRO_DB_USER') ?: 'root', getenv('SAGIPBRO_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    apCheck((bool) preg_match('/\Asagipbro_panel_[a-f0-9]{20}\z/', $name), 'Safe private database.');
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; $db->exec('USE `' . $name . '`'); $db->exec("SET time_zone = '+08:00'");
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) {
        $sql = trim($sql); if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE|USE)\b/i', $sql)) $db->exec($sql);
    }
    $staff = apInsert($db, 'users', ['full_name' => 'Private Panel Test', 'username' => 'panel_test', 'role' => 'admin', 'password_hash' => 'NOT-A-LOGIN']);
    // Exercise upgrades from existing Normal/Urgent enums, including repeat migration.
    $db->exec("ALTER TABLE announcements MODIFY priority ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal'");
    $base = ['body' => "Complete public instruction\nSecond paragraph & <script>window.panelXss = true</script>", 'created_by' => $staff, 'status' => 'Published', 'published_at' => '2020-01-01 12:00:00'];
    $urgent = apInsert($db, 'announcements', ['title' => 'Old urgent', 'priority' => 'Urgent'] + $base);
    migratePublicServices($db); migratePublicServices($db);
    apCheck((int) $db->query('SELECT COUNT(*) FROM announcements')->fetchColumn() === 1, 'Migration preserves records without seeding.');
    apCheck($db->query('SELECT priority FROM announcements')->fetchColumn() === 'Urgent', 'Enum expansion preserves priority.');
    foreach (['Low', 'Medium', 'High', 'Normal'] as $i => $priority) apInsert($db, 'announcements', ['title' => $priority . ' current', 'priority' => $priority, 'published_at' => '2021-01-0' . ($i + 1) . ' 12:00:00'] + $base);
    foreach ([['Draft', '2020-01-01 00:00:00', null], ['Archived', '2020-01-01 00:00:00', null], ['Published', '2099-01-01 00:00:00', null], ['Published', '2020-01-01 00:00:00', '2020-02-01 00:00:00'], ['Published', null, null]] as [$status, $published, $expires]) apInsert($db, 'announcements', ['title' => 'PRIVATE_HIDDEN', 'priority' => 'Urgent', 'status' => $status, 'published_at' => $published, 'expires_at' => $expires] + $base);
    $result = publicAnnouncementPanel($db);
    apCheck($result['urgent_count'] === 1 && count($result['rows']) === 5, 'Only five current publications and exact active urgent count.');
    apCheck(array_column($result['rows'], 'title') === ['Old urgent', 'Normal current', 'High current', 'Medium current', 'Low current'], 'Urgent before all others sorted by publication date.');
    apCheck(mkdir($directory, 0700), 'Private temporary directory.'); putenv('SAGIPBRO_SESSION_PATH=' . $directory);
    require_once $project . '/config/session.php'; $_SESSION['user_id'] = $staff; $_SESSION['role'] = 'admin'; $_SESSION['username'] = 'panel_test';
    $csrf = csrfToken(); $cookie = session_name() . '=' . session_id(); session_write_close();
    $environment = getenv(); $environment['SAGIPBRO_DB_NAME'] = $name; $environment['SAGIPBRO_BASE_URL'] = ''; unset($environment['PHP_CLI_SERVER_WORKERS']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $listen = stream_socket_get_name($socket, false); fclose($socket); $address = 'http://' . $listen;
    $router = '<?php $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($path === "/__panel_browser") { readfile(' . var_export($directory . '/browser.html', true) . '); return true; } if ($path === "/services.php") { ob_start(); require ' . var_export($project . '/services.php', true) . '; header_remove("X-Frame-Options"); ob_end_flush(); return true; } return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    apCheck(is_resource($server), 'Private HTTP server starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do { $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(25000); } while (microtime(true) < $deadline);
    $html = apRequest('services.php'); $dom = apDom($html);
    apCheck($dom->query('//body[contains(concat(" ", normalize-space(@class), " "), " services-page ")]')->length === 1, 'Services styling is scoped to the Services page.');
    apCheck(trim($dom->query('//*[@id="services-page-title"]')->item(0)->textContent) === 'Disaster Relief Services', 'Requested Services hero title is rendered.');
    $hero = $dom->query('//section[contains(concat(" ", normalize-space(@class), " "), " services-hero ")]')->item(0);
    apCheck(trim($dom->query('.//p', $hero)->item(0)->textContent) === 'Access essential relief resources, evacuation centers, distribution updates, and public reports in Barangay Bonuan Binloc.', 'Requested concise Services hero subtitle is rendered.');
    apCheck(trim($dom->query('.//span[contains(@class,"hero-chip")]', $hero)->item(0)->textContent) === 'Barangay Bonuan Binloc', 'Hero badge preserves the official barangay name.');
    apCheck($dom->query('.//nav[@aria-label="Breadcrumb"]//a[@href="index.php"]', $hero)->length === 1 && $dom->query('.//li[@aria-current="page"]', $hero)->length === 1, 'Hero breadcrumbs remain available.');
    $serviceRows = $dom->query('//div[contains(concat(" ", normalize-space(@class), " "), " public-services-main ")]//article[contains(concat(" ", normalize-space(@class), " "), " service-row ")]');
    $expectedServices = [
        ['Relief Resources', 'Organizes food, water, hygiene, medical, and shelter supplies by available quantity, unit, category, and stock condition.', 'bi-box-seam', 'resources.php#resource-directory', 'Browse available supplies'],
        ['Evacuation Centers', 'Presents center locations, capacity, current occupancy, and availability so options can be reviewed quickly.', 'bi-buildings', 'evacuation-centers.php#center-directory', 'Check center availability'],
        ['Relief Distribution', 'Find published schedules, locations, active distributions, planned supplies, and totals already distributed. Recipient details remain private.', 'bi-truck', 'distributions.php#distribution-directory', 'View distributions'],
        ['Reports', 'Review public resource availability, open evacuation capacity, and recent relief distributions from barangay records.', 'bi-file-earmark-bar-graph', 'reports.php#public-reports', 'Open public reports'],
    ];
    apCheck($serviceRows->length === count($expectedServices), 'All four existing service cards are preserved.');
    foreach ($expectedServices as $index => [$title, $description, $icon, $href, $label]) {
        $row = $serviceRows->item($index);
        apCheck(trim($dom->query('.//h3', $row)->item(0)->textContent) === $title && trim($dom->query('.//p', $row)->item(0)->textContent) === $description, 'Existing service title and description preserved: ' . $title);
        apCheck($dom->query('.//i[contains(concat(" ", normalize-space(@class), " "), " ' . $icon . ' ")]', $row)->length === 1, 'Existing service icon preserved: ' . $title);
        apCheck($dom->query('.//a[@href="' . $href . '"]', $row)->length === 1 && str_contains($row->textContent, $label), 'Entire service card retains its action and destination: ' . $title);
    }
    $cta = $dom->query('//section[@aria-labelledby="services-cta-title"]')->item(0);
    apCheck($cta instanceof DOMElement && str_contains($cta->textContent, 'Check the latest recorded availability'), 'Bottom Services CTA is preserved.');
    foreach (['resources.php#resource-directory' => 'Browse resources', 'contact.php#contact-details' => 'Contact information'] as $href => $label) {
        $link = $dom->query('.//a[@href="' . $href . '"]', $cta)->item(0);
        apCheck($link instanceof DOMElement && trim($link->textContent) === $label, 'Bottom CTA action is preserved: ' . $label);
    }
    apCheck(hash_file('sha256', $project . '/includes/navbar.php') === 'd20ae46eb48f4e68b424cfa123c8f39c18943fcd4d63f4ae5e1de65ca67eef87', 'Navbar source remains byte-identical.');
    apCheck(hash_file('sha256', $project . '/includes/footer.php') === '33b5c5b88562065629219d6a6b99ae1f5df0a822c47a2085aaa494458191bf52', 'Footer source remains byte-identical.');
    apCheck(!str_contains($html, 'PRIVATE_HIDDEN'), 'Hidden publications never enter HTML.');
    apCheck(!str_contains($html, '<script>window.panelXss') && str_contains($html, '&lt;script&gt;'), 'Preview and full text are escaped.');
    apCheck($dom->query('//nav[@aria-label="Public disaster services"]//a')->length === 4, 'Exactly four service navigation tabs.');
    apCheck($dom->query('//nav[@aria-label="Public disaster services"]//a[contains(@href,"announcements")]')->length === 0, 'Announcement tab removed.');
    apCheck($dom->query('//aside//details')->length === 5 && $dom->query('//aside//summary')->length === 5, 'Five clickable entries with native details fallback.');
    apCheck($dom->query('//aside//a[contains(@href,"announcements.php#")]')->length === 1, 'Full page is linked from panel.');
    foreach (['priority-high', 'priority-medium', 'success', 'danger'] as $tone) apCheck(str_contains($html, 'status-' . $tone), 'Priority tone ' . $tone . ' rendered.');
    foreach (['Urgent', 'High', 'Medium', 'Low', 'Normal'] as $priority) {
        $full = apRequest('announcements.php?' . http_build_query(['priority' => $priority, 'q' => $priority === 'Urgent' ? 'Old' : 'current']));
        apCheck(str_contains($full, '1 announcement found') && str_contains($full, 'data-public-pagination'), 'Full page search/priority/pagination preserved for ' . $priority);
    }
    $hugeBody = $base['body'] . "\n" . str_repeat("Detailed verified community safety instructions.\n", 180) . 'LONG_PANEL_BODY_END';
    $bodyUpdate = $db->prepare('UPDATE announcements SET body = ? WHERE id = ?');
    $bodyUpdate->execute([$hugeBody, $urgent]);
    $hugeHtml = apRequest('services.php');
    apCheck(str_contains($hugeHtml, 'LONG_PANEL_BODY_END') && apDom($hugeHtml)->query('//aside//summary')->length === 5, 'A huge full description remains available without hiding the five-notice list.');
    if (getenv('SAGIPBRO_PANEL_BROWSER') === '1') apBrowser($directory, $environment);
    $bodyUpdate->execute([$base['body'], $urgent]);
    for ($i = 0; $i < 7; $i++) apInsert($db, 'announcements', ['title' => 'Urgent overflow ' . $i, 'priority' => 'Urgent', 'published_at' => '2022-01-01 00:00:00'] + $base);
    $html = apRequest('services.php'); apCheck(str_contains($html, '8 active urgent announcements') && str_contains($html, 'Showing 5 of 8 urgent notices'), 'Badge counts urgent notices beyond the five visible entries.');
    $payload = ['title' => 'Admin created priority', 'body' => 'Verified public instruction', 'category' => 'General', 'audience' => 'All residents', 'status' => 'Published', 'published_at' => '2023-01-01T12:00', 'priority' => 'High', 'expires_at' => '2099-01-01T12:00'];
    $response = json_decode(apRequest('api/announcements.php', 'POST', $payload, 201), true); $id = (int) $response['id'];
    $record = json_decode(apRequest('api/announcements.php?id=' . $id), true)['data'][0];
    apCheck($record['priority'] === 'High' && $record['expires_at'] === '2099-01-01 12:00:00', 'Admin API saves and returns priority/expiry.');
    apRequest('api/announcements.php', 'POST', array_replace($payload, ['priority' => 'INVALID']), 422);
    apRequest('api/announcements.php', 'POST', array_replace($payload, ['expires_at' => '2026-02-31T12:00']), 422);
    foreach (['Urgent', 'Medium', 'Low'] as $priority) apRequest('api/announcements.php', 'PUT', array_replace($payload, ['id' => $id, 'priority' => $priority]));
    apRequest('api/announcements.php', 'DELETE', ['id' => $id]);
    apCheck((int) $db->query("SELECT COUNT(*) FROM announcements WHERE id = {$id} AND status = 'Archived'")->fetchColumn() === 1, 'Existing archive action retained.');
    $db->exec("UPDATE announcements SET status = 'Archived' WHERE title LIKE 'Urgent overflow%'");
    $db->exec("UPDATE announcements SET title = 'Updated urgent instruction' WHERE id = {$urgent}");
    $html = apRequest('services.php'); apCheck(str_contains($html, 'Updated urgent instruction') && !str_contains($html, 'Urgent overflow'), 'Refresh reflects updates and archives.');
    $db->exec("DELETE FROM announcements WHERE id = {$urgent}"); $html = apRequest('services.php');
    apCheck(!str_contains($html, 'Updated urgent instruction') && str_contains($html, '0 active urgent announcements'), 'Refresh reflects deletions and recomputes badge.');
    $db->exec('ALTER TABLE announcements CHANGE body content TEXT NOT NULL');
    $legacy = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', getenv('SAGIPBRO_DB_USER') ?: 'root', getenv('SAGIPBRO_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $legacy->exec("SET time_zone = '+08:00'");
    apCheck(count(publicAnnouncementPanel($legacy)['rows']) === 4, 'Legacy content column supported.');
    $db->exec("UPDATE announcements SET status = 'Archived'"); $html = apRequest('services.php');
    apCheck(str_contains($html, 'No current announcements') && str_contains($html, 'Browse available supplies'), 'Empty panel preserves service catalogue.');
    if (getenv('SAGIPBRO_PANEL_BROWSER') === '1') apBrowser($directory, $environment, true);
    $db->exec('DROP TABLE announcements'); $html = apRequest('services.php');
    apCheck(str_contains($html, 'Updates temporarily unavailable') && str_contains($html, 'Browse available supplies') && !str_contains($html, 'SQLSTATE'), 'Database failure remains honest without breaking Services.');
    fwrite(STDOUT, "PASS: visibility, ordering, urgent counts, escaping, full-page filters, admin writes, refresh, legacy schema, empty/outage states.\n");
} catch (Throwable $error) { $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); }
finally {
    if (is_resource($server)) { if (proc_get_status($server)['running']) proc_terminate($server); proc_close($server); }
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_panel_[a-f0-9]{20}\z/', $name)) $db->exec('DROP DATABASE `' . $name . '`');
    $resolved = realpath($directory); $temp = realpath(sys_get_temp_dir());
    if ($resolved && $temp && str_starts_with(strtolower($resolved), strtolower($temp . DIRECTORY_SEPARATOR)) && preg_match('/\Asagipbro-panel-[a-f0-9]{16}\z/', basename($resolved))) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname()); else @unlink($entry->getPathname()); } @rmdir($resolved);
    }
    fwrite(STDOUT, "Cleaned up isolated fixtures; application records were untouched.\n");
}
fwrite(STDOUT, ($failed ? 'FAILED: ' : 'PASSED: ') . $checks . " panel assertions.\n"); exit($failed ? 1 : 0);
