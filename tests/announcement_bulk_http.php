<?php
declare(strict_types=1);

/**
 * Run: php tests/announcement_bulk_http.php
 * Optional Chrome: SAGIPBRO_ANNOUNCEMENT_BULK_BROWSER=1.
 * Creates a random private database, sessions, and loopback server. Never reads
 * or writes application records; every fixture and temporary file is removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../database/public_services_migration.php';

$project = dirname(__DIR__);
$databaseName = 'sagipbro_bulk_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-bulk-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $address = ''; $identities = []; $checks = 0; $failed = false;

function bulkCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function bulkInsert(PDO $db, string $table, array $values): int
{
    $statement = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $statement->execute(array_values($values));
    return (int) $db->lastInsertId();
}

function bulkRequest(string $route, string $method = 'GET', ?array $data = null, ?string $role = 'admin', bool $token = true): array
{
    global $address, $identities;
    $headers = ['Accept: application/json', 'Connection: close'];
    if ($role !== null) $headers[] = 'Cookie: ' . $identities[$role]['cookie'];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        if ($token && $role !== null) $headers[] = 'X-CSRF-Token: ' . $identities[$role]['csrf'];
    }
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    if ($data !== null) $options['content'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $stream = @fopen($address . '/' . ltrim($route, '/'), 'rb', false, stream_context_create(['http' => $options]));
    if (!$stream) throw new LogicException('Private bulk test server unavailable.');
    $body = (string) stream_get_contents($stream); $meta = stream_get_meta_data($stream); fclose($stream);
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $meta['wrapper_data'][0] ?? '', $status);
    bulkCheck(!preg_match('/SQLSTATE|Fatal error|Warning:|Stack trace:|PRIVATE_BULK_AUDIT_FAILURE/i', $body), 'Responses must not expose SQL, audit internals, or technical errors.');
    return ['status' => (int) ($status[1] ?? 0), 'body' => $body, 'json' => json_decode($body, true)];
}

function bulkApi(string $method, ?array $data = null, int $expected = 200, ?string $role = 'admin', bool $token = true, array $query = []): array
{
    $response = bulkRequest('api/announcements.php' . ($query ? '?' . http_build_query($query) : ''), $method, $data, $role, $token);
    bulkCheck($response['status'] === $expected, $method . ' announcement API expected ' . $expected . ', got ' . $response['status'] . ': ' . substr($response['body'], 0, 300));
    if ($expected !== 419) bulkCheck(is_array($response['json']), 'Announcement API returns valid JSON.');
    return $response['json'] ?? [];
}

function bulkSnapshot(PDO $db): array
{
    return [$db->query('SELECT * FROM announcements ORDER BY id')->fetchAll(), (int) $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()];
}

function bulkRecord(PDO $db, int $id): ?array
{
    $statement = $db->prepare('SELECT * FROM announcements WHERE id = ?'); $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function bulkDom(string $html): DOMXPath
{
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function bulkBrowser(string $directory, array $environment, PDO $db): void
{
    global $address;
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    bulkCheck(is_file($chrome), 'Set SAGIPBRO_CHROME_BINARY for optional Chrome checks.');
    $script = <<<'JS'
const output = document.querySelector('pre'), frame = document.querySelector('iframe');
let checks = 0;
const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
const settle = (ms = 100) => new Promise(resolve => setTimeout(resolve, ms));
const until = async (condition, label) => { for (let n = 0; n < 120; n++) { if (condition()) return; await settle(50); } throw new Error('Timed out: ' + label); };
const load = async url => { await new Promise(resolve => { frame.onload = resolve; frame.src = url; }); await settle(); };
let doc, win, table, modal, form;
const ready = async () => { await until(() => table?.getAttribute('aria-busy') === 'false' && table.querySelectorAll('[data-announcement-select]').length > 0, 'announcement rows'); };
const boxes = () => [...table.querySelectorAll('[data-announcement-select]')];
const toolbar = () => doc.querySelector('#announcementBulkToolbar');
const count = () => Number((doc.querySelector('#announcementSelectionCount').textContent.match(/\d+/) || [0])[0]);
const selected = () => boxes().filter(box => box.checked).map(box => Number(box.dataset.announcementSelect));
const cleared = label => check(toolbar().hidden && selected().length === 0 && count() === 0 && !doc.querySelector('#announcementSelectAll').checked && !doc.querySelector('#announcementSelectAll').indeterminate, label);
const choose = (amount = 1) => { boxes().slice(0, amount).forEach(box => { if (!box.checked) box.click(); }); check(count() === amount && !toolbar().hidden, 'Selection count reflects visible checked rows'); };
const filter = async (id, value) => { const control = doc.getElementById(id); control.value = value; control.dispatchEvent(new win.Event(control.type === 'search' ? 'input' : 'change', {bubbles: true})); await settle(350); await ready(); };
const modalEvent = async (name, action) => { let occurred = false; modal.addEventListener(name, () => { occurred = true; }, {once: true}); action(); await until(() => occurred, name); };
const close = () => modalEvent('hidden.bs.modal', () => modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click());
const open = async action => { await modalEvent('shown.bs.modal', () => doc.querySelector(`[data-announcement-bulk-action="${action}"]`).click()); await settle(180); };
const submit = async () => { await modalEvent('hidden.bs.modal', () => form.requestSubmit(doc.querySelector('#bulkAnnouncementConfirm'))); await ready(); };
(async () => {
    frame.style.width = '1440px'; frame.style.height = '900px';
    await load('/pages/announcements/index.php'); doc = frame.contentDocument; win = frame.contentWindow;
    table = doc.querySelector('#announcementsTable'); modal = doc.querySelector('#bulkAnnouncementModal'); form = doc.querySelector('#bulkAnnouncementForm');
    await ready();
    const createModal = doc.querySelector('#addAnnouncementModal'), createForm = doc.querySelector('#addAnnouncementForm');
    for (let number = 1; number <= 2; number++) {
        await new Promise(resolve => { createModal.addEventListener('shown.bs.modal', resolve, {once: true}); doc.querySelector('[data-bs-target="#addAnnouncementModal"]').click(); });
        Object.entries({title: 'UIPRIORITY urgent ' + number, content: 'Two simultaneous urgent announcement verification.', category: 'Advisory', audience: 'All residents', status: 'Published', priority: 'Urgent'}).forEach(([key, value]) => { createForm.elements[key].value = value; });
        await new Promise(resolve => { createModal.addEventListener('hidden.bs.modal', resolve, {once: true}); createForm.requestSubmit(createForm.querySelector('[type="submit"]')); });
        await ready();
    }
    const savedUrgent = [...table.querySelectorAll('[data-record-json]')].map(button => JSON.parse(button.dataset.recordJson)).filter(record => record.title.startsWith('UIPRIORITY urgent'));
    check(savedUrgent.length === 2 && savedUrgent.every(record => record.priority === 'Urgent' && record.status === 'Published'), 'The actual admin form saves two consecutive published Urgent notices without clearing either priority');
    const editModal = doc.querySelector('#editAnnouncementModal');
    await new Promise(resolve => { editModal.addEventListener('shown.bs.modal', resolve, {once: true}); table.querySelector('[data-announcement-edit="' + savedUrgent[0].id + '"]').click(); });
    check(doc.querySelector('#editAnnouncementForm').elements.priority.value === 'Urgent', 'Reopening the editor retains the selected Urgent priority');
    await new Promise(resolve => { editModal.addEventListener('hidden.bs.modal', resolve, {once: true}); editModal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click(); });
    await filter('announcementSearch', 'UIBULK Item');
    check(win.sagipbroAnnouncementApi.canBulkManage === true && boxes().length === 10, 'Admin gets ten real current-page selectable rows');
    cleared('Initial selection toolbar is hidden');
    check(!win.bulkXss, 'Database announcement markup is escaped');
    check(doc.querySelector('#addAnnouncementForm') && doc.querySelector('#editAnnouncementForm') && doc.querySelector('#deleteAnnouncementForm'), 'Existing create/edit/individual-archive forms retained');
    let mutations = [];
    const nativeFetch = win.fetch.bind(win);
    win.fetch = (url, options = {}) => { if (options.method && options.method !== 'GET') mutations.push(JSON.parse(options.body)); return nativeFetch(url, options); };
    choose();
    check(doc.querySelector('#announcementSelectAll').indeterminate && !doc.querySelector('#announcementSelectAll').checked, 'Partial selection makes header checkbox indeterminate');
    doc.querySelector('#announcementSelectAll').click();
    check(count() === 10 && selected().length === 10 && doc.querySelector('#announcementSelectAll').checked, 'Select All selects only the current ten records');
    doc.querySelector('#announcementClearSelection').click(); cleared('Clear selection resets all checkboxes and count');
    choose(2); await open('delete');
    check(/\b2\b/.test(doc.querySelector('#bulkAnnouncementMessage').textContent) && /permanent|cannot|undo/i.test(doc.querySelector('#bulkAnnouncementHelp').textContent), 'Delete confirmation includes exact count and permanent-removal warning');
    check(mutations.length === 0, 'Opening confirmation sends no mutation');
    await close(); check(mutations.length === 0 && selected().length === 2, 'Cancel never sends a mutation');
    doc.querySelector('#announcementClearSelection').click();
    for (const [id, value] of [['announcementCategory', 'Advisory'], ['announcementStatus', 'Published'], ['announcementAudience', 'All residents'], ['announcementPriority', 'High']]) {
        choose(); const control = doc.getElementById(id); control.value = value; control.dispatchEvent(new win.Event('change', {bubbles: true}));
        cleared(id + ' change clears stale selection immediately'); await ready(); await filter(id, '');
    }
    choose(); const search = doc.querySelector('#announcementSearch'); search.value = 'UIBULK Item 0'; search.dispatchEvent(new win.Event('input', {bubbles: true}));
    cleared('Search clears stale selection before debounce'); await settle(400); await ready(); await filter('announcementSearch', 'UIBULK Item');
    choose(); doc.querySelector('[aria-label="Next"]').click(); cleared('Next page clears selection immediately'); await ready();
    check(win.SagipbroPagination.get(table).state.page === 2 && selected().length === 0, 'Second page never carries first-page selection');
    choose(); const size = doc.querySelector('#announcementsTablePageSize'); size.value = '25'; size.dispatchEvent(new win.Event('change', {bubbles: true}));
    cleared('Page-size change clears selection immediately'); await ready();
    check(boxes().length === 23, 'Larger page displays matching real records');
    await filter('announcementsTablePageSize', '10');
    choose();
    let failGet = true;
    win.fetch = (url, options = {}) => { if (failGet && (!options.method || options.method === 'GET') && String(url).includes('/api/announcements.php')) { failGet = false; return Promise.resolve(new win.Response(JSON.stringify({error: 'Fixture retrieval failed.'}), {status: 503, headers: {'Content-Type': 'application/json'}})); } return nativeFetch(url, options); };
    const priority = doc.querySelector('#announcementPriority'); priority.value = 'High'; priority.dispatchEvent(new win.Event('change', {bubbles: true}));
    cleared('Failed filter load still clears selection immediately');
    await until(() => table.getAttribute('aria-busy') === 'false' && /Unable to load/.test(doc.querySelector('[data-pagination-summary]').textContent), 'simulated load error');
    win.fetch = (url, options = {}) => { if (options.method && options.method !== 'GET') mutations.push(JSON.parse(options.body)); return nativeFetch(url, options); };
    await filter('announcementPriority', '');
    for (const [id, value] of [['announcementCategory', 'Advisory'], ['announcementStatus', 'Published'], ['announcementAudience', 'All residents'], ['announcementPriority', 'High']]) await filter(id, value);
    doc.querySelector('[aria-label="Next"]').click(); await ready(); doc.querySelector('[aria-label="Next"]').click(); await ready();
    check(boxes().length === 3, 'Last matching page starts with three records');
    doc.querySelector('#announcementSelectAll').click(); const deletedIds = selected(); await open('delete'); await submit();
    check(win.SagipbroPagination.get(table).state.page === 2 && boxes().length === 10, 'Deleting final-page selection clamps to the prior valid page');
    check(search.value === 'UIBULK Item' && ['announcementCategory', 'announcementStatus', 'announcementAudience', 'announcementPriority'].every(id => doc.getElementById(id).value), 'Bulk reload preserves search and all active filters');
    cleared('Successful permanent delete clears selection');
    choose(2); const archivedIds = selected(); await open('archive');
    check(/\b2\b/.test(doc.querySelector('#bulkAnnouncementMessage').textContent) && /archiv/i.test(doc.querySelector('#bulkAnnouncementTitle').textContent), 'Archive confirmation names the action and exact selected count');
    let failPost = true;
    win.fetch = (url, options = {}) => { if (failPost && options.method === 'POST') { failPost = false; return Promise.resolve(new win.Response(JSON.stringify({error: 'Fixture mutation failed safely.'}), {status: 409, headers: {'Content-Type': 'application/json'}})); } return nativeFetch(url, options); };
    form.requestSubmit(doc.querySelector('#bulkAnnouncementConfirm'));
    await until(() => /Fixture mutation failed safely/.test(doc.querySelector('#bulkAnnouncementError').textContent), 'inline mutation error');
    check(modal.classList.contains('show') && !doc.querySelector('#bulkAnnouncementConfirm').disabled && selected().length === 2, 'Failure remains visible and retryable without changing selection');
    let submitCount = 0;
    win.fetch = async (url, options = {}) => { if (options.method === 'POST') { submitCount++; mutations.push(JSON.parse(options.body)); await settle(160); } return nativeFetch(url, options); };
    await modalEvent('hidden.bs.modal', () => {
        form.dispatchEvent(new win.Event('submit', {bubbles: true, cancelable: true})); form.dispatchEvent(new win.Event('submit', {bubbles: true, cancelable: true}));
        check(doc.querySelector('#bulkAnnouncementConfirm').disabled, 'Pending mutation disables confirm');
    });
    await ready();
    check(submitCount === 1, 'Double submit issues exactly one mutation'); cleared('Archive clears selection after success');
    win.fetch = nativeFetch;
    await filter('announcementStatus', 'Archived');
    const restore = table.querySelector('[data-announcement-restore]'); const restoredId = Number(restore.dataset.announcementRestore);
    await modalEvent('shown.bs.modal', () => restore.click());
    check(/\b1\b/.test(doc.querySelector('#bulkAnnouncementMessage').textContent) && /draft/i.test(doc.querySelector('#bulkAnnouncementHelp').textContent), 'Individual restore confirms one archived notice returns to Draft');
    await modalEvent('hidden.bs.modal', () => form.requestSubmit(doc.querySelector('#bulkAnnouncementConfirm'))); await ready();
    const restored = await nativeFetch('/api/announcements.php?id=' + restoredId).then(response => response.json());
    check(restored.data[0].status === 'Draft' && restored.data[0].published_at === '2020-01-01 12:00:00', 'Restore preserves prior publication history but never republishes automatically');
    await filter('announcementStatus', 'Draft');
    check(table.querySelector('[data-announcement-edit="' + restoredId + '"]'), 'Restored draft can use the original edit workflow');
    await filter('announcementStatus', 'Published');
    // Virtual-time Chrome can freeze an iframe's compositor transition at its
    // initial -50px transform even after shown.bs.modal. Measure final geometry
    // without animation; real Bootstrap show/hide workflows were exercised above.
    const geometryStyle = doc.createElement('style'); geometryStyle.textContent = '.modal.fade .modal-dialog,.modal-backdrop.fade{transition:none!important}'; doc.head.append(geometryStyle);
    for (const width of [1440, 390, 320]) {
        frame.style.width = width + 'px'; await settle(120); choose(2); await open('delete');
        check(win.innerWidth === width && doc.documentElement.scrollWidth <= width + 1, width + 'px viewport has no page-wide overflow');
        const bounds = modal.querySelector('.modal-content').getBoundingClientRect(), confirm = doc.querySelector('#bulkAnnouncementConfirm').getBoundingClientRect();
        check(bounds.left >= -1 && bounds.right <= width + 1 && bounds.top >= -1 && bounds.bottom <= win.innerHeight + 1, width + 'px confirmation fits viewport: ' + JSON.stringify({left: bounds.left, right: bounds.right, top: bounds.top, bottom: bounds.bottom, viewport: [win.innerWidth, win.innerHeight], modal: modal.className, transform: win.getComputedStyle(modal.querySelector('.modal-dialog')).transform, dialog: modal.querySelector('.modal-dialog').className}));
        check(confirm.height >= 40 && confirm.left >= bounds.left && confirm.right <= bounds.right + 1 && confirm.bottom <= bounds.bottom + 1, width + 'px confirm remains tappable and visible');
        const headerBox = doc.querySelector('#announcementSelectAll');
        check(toolbar().scrollWidth <= toolbar().clientWidth + 1 && (headerBox.getAttribute('aria-label') || headerBox.closest('label')?.textContent.trim()), width + 'px selection toolbar wraps and header checkbox has accessible name');
        await close(); doc.querySelector('#announcementClearSelection').click();
    }
    await load('/__bulk_official');
    check(!frame.contentDocument.querySelector('#announcementSelectAll,#announcementBulkToolbar,#bulkAnnouncementModal') && frame.contentWindow.sagipbroAnnouncementApi.canBulkManage === false, 'Officials retain the register without admin bulk/delete/restore controls');
    output.textContent = JSON.stringify({ok: true, checks, deletedIds, archivedIds, restoredId});
})().catch(error => { output.textContent = JSON.stringify({ok: false, checks, error: error.message}); });
JS;
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe title="Announcement register"></iframe><script>' . $script . '</script>');
    $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=45000', '--dump-dom', $address . '/__bulk_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    bulkCheck(is_resource($process), 'Private Chrome process starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 55;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($process)['running']) proc_terminate($process); proc_close($process);
    preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/dom.html'), $match);
    $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    bulkCheck(($result['ok'] ?? false) === true, 'Browser checks: ' . json_encode($result));
    foreach ($result['deletedIds'] as $id) bulkCheck(bulkRecord($db, (int) $id) === null, 'Browser permanent deletion removed exactly the selected fixture.');
    foreach ($result['archivedIds'] as $id) bulkCheck((bulkRecord($db, (int) $id)['status'] ?? null) === ((int) $id === (int) $result['restoredId'] ? 'Draft' : 'Archived'), 'Browser archive/restore persisted the expected status.');
    fwrite(STDOUT, 'PASS: ' . $result['checks'] . " desktop/mobile Chrome bulk-selection/confirmation/mutation assertions.\n");
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    bulkCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Safe MySQL test connection settings.');
    $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    bulkCheck((bool) preg_match('/\Asagipbro_bulk_[a-f0-9]{20}\z/', $databaseName), 'Safe isolated database identifier.');
    $db->exec('CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $db->exec('USE `' . $databaseName . '`'); $db->exec("SET time_zone = '+08:00'");
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) {
        $sql = trim($sql); if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE|USE)\b/i', $sql)) $db->exec($sql);
    }
    $db->exec("ALTER TABLE announcements MODIFY priority ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal'");
    migratePublicServices($db); migratePublicServices($db);
    $priorityType = $db->query("SHOW COLUMNS FROM announcements LIKE 'priority'")->fetch()['Type'];
    foreach (['Normal', 'Urgent', 'High', 'Medium', 'Low'] as $priority) bulkCheck(str_contains($priorityType, "'" . $priority . "'"), 'Legacy enum migration supports ' . $priority . ' and can run twice.');
    bulkCheck(mkdir($directory, 0700), 'Private test directory created.');
    putenv('SAGIPBRO_SESSION_PATH=' . $directory); require_once $project . '/config/session.php';
    foreach (['admin', 'official', 'volunteer'] as $role) {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_id(''); session_start(); }
        $id = bulkInsert($db, 'users', ['full_name' => 'Bulk ' . $role, 'username' => 'bulk_' . $role, 'password_hash' => 'TEST-NOT-LOGIN', 'role' => $role]);
        $_SESSION = ['user_id' => $id, 'role' => $role, 'full_name' => 'Bulk ' . $role, 'username' => 'bulk_' . $role];
        $identities[$role] = ['id' => $id, 'cookie' => session_name() . '=' . session_id(), 'session' => session_id(), 'csrf' => csrfToken()]; session_write_close();
    }
    $base = ['body' => 'Confirmed private fixture body.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Published', 'priority' => 'High', 'published_at' => '2020-01-01 12:00:00', 'created_by' => $identities['admin']['id']];
    $published = bulkInsert($db, 'announcements', ['title' => 'Bulk published target'] + $base);
    $draft = bulkInsert($db, 'announcements', ['title' => 'Bulk draft target', 'status' => 'Draft', 'published_at' => null] + $base);
    $archived = bulkInsert($db, 'announcements', ['title' => 'Bulk archived target', 'status' => 'Archived'] + $base);
    $unrelated = bulkInsert($db, 'announcements', ['title' => 'Bulk unrelated public notice', 'priority' => 'Low'] + $base);
    $untouched = bulkRecord($db, $unrelated);
    $environment = getenv();
    foreach (['SAGIPBRO_DB_HOST' => $host, 'SAGIPBRO_DB_PORT' => $port, 'SAGIPBRO_DB_USER' => $user === false ? 'root' : $user, 'SAGIPBRO_DB_PASSWORD' => $password === false ? '' : $password, 'SAGIPBRO_DB_NAME' => $databaseName, 'SAGIPBRO_SESSION_PATH' => $directory, 'SAGIPBRO_BASE_URL' => ''] as $key => $value) $environment[$key] = $value;
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $listen = stream_socket_get_name($socket, false); fclose($socket); $address = 'http://' . $listen;
    $router = '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if($path==="/__bulk_browser"){setcookie(' . var_export(session_name(), true) . ',' . var_export($identities['admin']['session'], true) . ',["path"=>"/","httponly"=>true,"samesite"=>"Lax"]);readfile(' . var_export($directory . '/browser.html', true) . ');return true;} if($path==="/__bulk_official"){require_once ' . var_export($project . '/config/session.php', true) . ';session_write_close();$_SESSION["role"]="official";$_SESSION["user_id"]=' . (int) $identities['official']['id'] . ';$path="/pages/announcements/index.php";} if(in_array($path,["/pages/announcements/index.php","/index.php","/announcements.php"],true)){$target=' . var_export($project, true) . '.$path;chdir(dirname($target));ob_start();require $target;header_remove("X-Frame-Options");ob_end_flush();return true;}return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    bulkCheck(is_resource($server), 'Private loopback PHP server starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do { $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(25000); } while (microtime(true) < $deadline);

    $before = bulkSnapshot($db);
    bulkApi('GET', null, 401, null);
    foreach (['bulk_archive', 'bulk_delete'] as $action) {
        bulkApi('POST', ['action' => $action, 'ids' => [$published]], 401, null);
        foreach (['official', 'volunteer'] as $role) bulkApi('POST', ['action' => $action, 'ids' => [$published]], 403, $role);
        bulkApi('POST', ['action' => $action, 'ids' => [$published]], 419, 'admin', false);
        foreach ([null, [], [$published, $published], ['' . $published], [1.0], [true], [null], [[1]], [0], [-1], [4294967296], ['1 OR 1=1'], ['bad' => $published], (object) ['0' => $published], range(1, 101)] as $ids) bulkApi('POST', ['action' => $action, 'ids' => $ids], 422);
        bulkApi('POST', ['action' => $action], 422);
        bulkApi('POST', ['action' => $action, 'ids' => [$published, 4294967295]], 404);
    }
    bulkApi('PUT', ['id' => $archived, 'action' => 'restore'], 403, 'official');
    bulkApi('PUT', ['id' => $archived, 'action' => 'restore'], 403, 'volunteer');
    bulkApi('PUT', ['id' => $archived, 'action' => 'restore'], 419, 'admin', false);
    bulkApi('PUT', ['id' => 4294967295, 'action' => 'restore'], 404);
    bulkApi('PUT', ['id' => $published, 'action' => 'restore'], 409);
    bulkCheck(bulkSnapshot($db) === $before, 'Invalid, unauthorized, missing-ID, CSRF, and restore-conflict requests leave every record and audit log unchanged.');
    $officialPage = bulkRequest('pages/announcements/index.php', 'GET', null, 'official');
    bulkCheck($officialPage['status'] === 200 && bulkDom($officialPage['body'])->query('//*[@id="announcementSelectAll" or @id="announcementBulkToolbar" or @id="bulkAnnouncementModal"]')->length === 0, 'Officials retain their page without administrator-only selection/destructive controls.');

    $result = bulkApi('POST', ['action' => 'bulk_archive', 'ids' => [$published, $draft, $archived]]);
    bulkCheck($result['selected_count'] === 3 && $result['affected_count'] === 2 && $result['unchanged_count'] === 1, 'Bulk archive accurately reports changed and already-archived records.');
    foreach ([$published, $draft, $archived] as $id) bulkCheck(bulkRecord($db, $id)['status'] === 'Archived', 'Bulk archive retains selected database rows.');
    bulkCheck(bulkRecord($db, $unrelated) === $untouched, 'Bulk archive leaves unrelated records untouched.');
    $logs = $db->query("SELECT action, entity_id, details FROM activity_logs WHERE entity_type='announcement' ORDER BY id")->fetchAll();
    bulkCheck(array_column($logs, 'action') === ['archive', 'archive', 'bulk_archive'], 'Archive writes one per-changed-record audit and one transaction summary.');
    $summary = json_decode($logs[2]['details'], true);
    bulkCheck($summary['selected_ids'] === [$published, $draft, $archived] && $summary['affected_ids'] === [$published, $draft] && $summary['selected_count'] === 3 && $summary['affected_count'] === 2, 'Summary audit identifies exactly selected and changed announcement IDs.');
    $public = bulkRequest('announcements.php');
    bulkCheck($public['status'] === 200 && !str_contains($public['body'], 'Bulk published target') && str_contains($public['body'], 'Bulk unrelated public notice'), 'Archived notices disappear from public listings without hiding unrelated publications.');
    foreach (['index.php', 'services.php'] as $route) {
        $public = bulkRequest($route);
        bulkCheck($public['status'] === 200 && !str_contains($public['body'], 'Bulk published target') && str_contains($public['body'], 'Bulk unrelated public notice'), 'Archive visibility updates the database-backed public homepage/panel: ' . $route);
    }
    bulkCheck(bulkRequest('announcements.php?view=' . $published)['status'] === 404, 'An archived public detail link cannot expose its body.');
    $adminArchived = bulkApi('GET', null, 200, 'admin', true, ['paginate' => 1, 'status' => 'Archived']);
    bulkCheck((int) $adminArchived['pagination']['total'] === 3, 'Archived notices remain in the admin filter and pagination.');
    $archiveAgain = bulkApi('POST', ['action' => 'bulk_archive', 'ids' => [$published]]);
    bulkCheck($archiveAgain['affected_count'] === 0 && $archiveAgain['unchanged_count'] === 1, 'Re-archiving is a safe accurately reported no-op.');
    $restoredBefore = bulkRecord($db, $published); bulkApi('PUT', ['id' => $published, 'action' => 'restore']);
    $restored = bulkRecord($db, $published);
    bulkCheck($restored['status'] === 'Draft' && $restored['published_at'] === $restoredBefore['published_at'] && $restored['title'] === $restoredBefore['title'] && $restored['body'] === $restoredBefore['body'] && $restored['priority'] === $restoredBefore['priority'], 'Restore returns archived content to private Draft while preserving content, priority and publication history.');
    bulkCheck(bulkRequest('announcements.php?view=' . $published)['status'] === 404, 'A restored Draft remains private.');
    bulkApi('PUT', ['id' => $published, 'title' => 'Bulk republished edit', 'body' => 'Verified revised instruction.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Published', 'priority' => 'Urgent']);
    bulkCheck(bulkRequest('announcements.php?view=' . $published)['status'] === 200, 'Existing edit/publish workflow safely republishes a restored draft.');
    $official = bulkApi('POST', ['title' => 'Official original workflow', 'body' => 'Original editor preserved.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Draft'], 201, 'official');
    bulkApi('PUT', ['id' => $official['id'], 'title' => 'Official edited workflow', 'body' => 'Original editor preserved.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Published'], 200, 'official');
    bulkApi('DELETE', ['id' => $official['id']], 200, 'official');
    bulkCheck(bulkRecord($db, (int) $official['id'])['status'] === 'Archived', 'Existing individual DELETE remains an archive for officials, not permanent deletion.');

    $db->exec('CREATE TABLE bulk_fixture_reference (id INT PRIMARY KEY, announcement_id INT UNSIGNED NOT NULL, FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE RESTRICT) ENGINE=InnoDB');
    $db->exec('INSERT INTO bulk_fixture_reference VALUES (1,' . $draft . ')');
    $before = bulkSnapshot($db); bulkApi('POST', ['action' => 'bulk_delete', 'ids' => [$published, $draft]], 409);
    bulkCheck(bulkSnapshot($db) === $before, 'A foreign-key conflict rolls back every selected delete and audit entry.');
    $db->exec('DROP TABLE bulk_fixture_reference');
    $db->exec("CREATE TRIGGER bulk_fail_audit BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRIVATE_BULK_AUDIT_FAILURE'");
    $before = bulkSnapshot($db);
    bulkApi('POST', ['action' => 'bulk_archive', 'ids' => [$published, $unrelated]], 500);
    bulkApi('POST', ['action' => 'bulk_delete', 'ids' => [$published, $draft]], 500);
    bulkApi('PUT', ['id' => $draft, 'action' => 'restore'], 500);
    bulkCheck(bulkSnapshot($db) === $before, 'Audit failure atomically rolls back archive, delete, and restore instead of silently losing audit history.');
    $db->exec('DROP TRIGGER bulk_fail_audit');
    $logStart = (int) $db->query('SELECT MAX(id) FROM activity_logs')->fetchColumn();
    $deleted = bulkApi('POST', ['action' => 'bulk_delete', 'ids' => [$published, $draft]]);
    bulkCheck($deleted['selected_count'] === 2 && $deleted['affected_count'] === 2 && bulkRecord($db, $published) === null && bulkRecord($db, $draft) === null && bulkRecord($db, $unrelated) === $untouched, 'Permanent delete removes only the selected records and reports the exact count.');
    $logs = $db->query('SELECT action,entity_id,details FROM activity_logs WHERE id > ' . $logStart . ' ORDER BY id')->fetchAll();
    bulkCheck(array_column($logs, 'action') === ['delete', 'delete', 'bulk_delete'] && json_decode($logs[2]['details'], true)['selected_ids'] === [$published, $draft], 'Permanent-delete audit history survives deletion and identifies every selected record.');
    $capIds = [];
    for ($i = 0; $i < 100; $i++) $capIds[] = bulkInsert($db, 'announcements', ['title' => sprintf('Limit fixture %03d', $i), 'priority' => 'Medium'] + $base);
    $cap = bulkApi('POST', ['action' => 'bulk_archive', 'ids' => $capIds]);
    bulkCheck($cap['selected_count'] === 100 && $cap['affected_count'] === 100, 'Maximum allowed selection of 100 real records works.');
    $filtered = bulkApi('GET', null, 200, 'admin', true, ['paginate' => 1, 'q' => 'Limit fixture', 'priority' => 'Medium', 'status' => 'Archived', 'category' => 'Advisory', 'audience' => 'All residents', 'per_page' => 25, 'page' => 4]);
    bulkCheck((int) $filtered['pagination']['total'] === 100 && count($filtered['data']) === 10 && (int) $filtered['pagination']['page'] === 4, 'Priority combines with search, category, status, audience and fixed ten-row pagination.');
    $empty = bulkApi('GET', null, 200, 'admin', true, ['paginate' => 1, 'q' => 'Limit fixture', 'priority' => 'Urgent']);
    bulkCheck($empty['data'] === [] && (int) $empty['pagination']['total'] === 0, 'Priority filtering returns honest empty results.');
    $priorityPayload = ['body' => 'Priority persistence verification.', 'category' => 'Advisory', 'audience' => 'All residents', 'status' => 'Published', 'published_at' => '2020-01-01T12:00'];
    $priorityIds = [];
    foreach (['Normal', 'Urgent', 'High', 'Medium', 'Low'] as $priority) {
        $createdNotice = bulkApi('POST', ['title' => 'Priority regression ' . $priority, 'priority' => $priority] + $priorityPayload, 201);
        $priorityIds[$priority] = (int) $createdNotice['id'];
        bulkCheck(bulkRecord($db, (int) $createdNotice['id'])['priority'] === $priority, 'Create preserves exactly selected priority: ' . $priority);
    }
    $secondUrgent = bulkApi('POST', ['title' => 'Priority regression second urgent', 'priority' => 'Urgent'] + $priorityPayload, 201);
    $urgentList = bulkApi('GET', null, 200, 'admin', true, ['paginate' => 1, 'q' => 'Priority regression', 'priority' => 'Urgent']);
    bulkCheck((int) $urgentList['pagination']['total'] === 2 && bulkRecord($db, $priorityIds['Urgent'])['priority'] === 'Urgent' && bulkRecord($db, (int) $secondUrgent['id'])['priority'] === 'Urgent', 'Two published Urgent announcements coexist without replacing or clearing either priority.');
    $urgentDirectory = bulkDom(bulkRequest('announcements.php?q=Priority%20regression&priority=Urgent')['body']);
    bulkCheck($urgentDirectory->query('//*[@id="announcement-directory"]//article')->length === 2, 'Public priority filter displays both Urgent announcements.');
    bulkApi('PUT', ['id' => $priorityIds['Normal'], 'title' => 'Priority regression edited urgent', 'priority' => 'Urgent'] + $priorityPayload);
    bulkCheck(bulkRecord($db, $priorityIds['Normal'])['priority'] === 'Urgent', 'Editing one announcement to Urgent preserves the other Urgent notices.');
    $db->exec("CREATE TRIGGER priority_fixture_insert BEFORE INSERT ON announcements FOR EACH ROW SET NEW.priority = 'Normal'");
    $beforePriorityFailure = bulkSnapshot($db);
    bulkApi('POST', ['title' => 'Priority regression rejected conversion', 'priority' => 'Urgent'] + $priorityPayload, 503);
    bulkCheck(bulkSnapshot($db) === $beforePriorityFailure, 'Silently changed priority rolls back the entire create and audit entry.');
    $db->exec('DROP TRIGGER priority_fixture_insert');
    $db->exec("CREATE TRIGGER priority_fixture_update BEFORE UPDATE ON announcements FOR EACH ROW SET NEW.priority = 'Normal'");
    bulkApi('PUT', ['id' => $priorityIds['Urgent'], 'title' => 'Priority regression rejected edit', 'priority' => 'Urgent'] + $priorityPayload, 503);
    bulkCheck(bulkSnapshot($db) === $beforePriorityFailure, 'Silently changed priority rolls back edited content and audit entry.');
    $db->exec('DROP TRIGGER priority_fixture_update');
    fwrite(STDOUT, "PASS: bulk safeguards, legacy priority migration, all five priorities, two simultaneous Urgent notices, public filtering, edits, and rollback when priority is silently changed.\n");
    if (getenv('SAGIPBRO_ANNOUNCEMENT_BULK_BROWSER') === '1') {
        for ($i = 1; $i <= 23; $i++) bulkInsert($db, 'announcements', ['title' => sprintf('UIBULK Item %02d', $i) . ($i === 23 ? ' <img src=x onerror=window.bulkXss=true>' : '')] + $base);
        bulkBrowser($directory, $environment, $db);
        bulkCheck(bulkRecord($db, $unrelated) === $untouched, 'Browser mutations never touch unrelated fixture records.');
    }
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    if (is_resource($server)) { if (proc_get_status($server)['running']) proc_terminate($server); proc_close($server); }
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_bulk_[a-f0-9]{20}\z/', $databaseName)) {
        try { $db->exec('DROP DATABASE `' . $databaseName . '`'); } catch (Throwable $error) { $failed = true; fwrite(STDERR, 'Private fixture cleanup pending: ' . $databaseName . "\n"); }
    }
    $resolved = realpath($directory); $temp = realpath(sys_get_temp_dir());
    if ($resolved && $temp && str_starts_with(strtolower($resolved), strtolower($temp . DIRECTORY_SEPARATOR)) && preg_match('/\Asagipbro-bulk-[a-f0-9]{16}\z/', basename($resolved))) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname()); else @unlink($entry->getPathname()); }
        @rmdir($resolved);
    }
    fwrite(STDOUT, "Cleaned up isolated fixtures; application records were untouched.\n");
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED: ' : 'PASSED: ') . $checks . " announcement bulk HTTP/data assertions.\n");
exit($failed ? 1 : 0);
