<?php
declare(strict_types=1);

/**
 * Isolated global bulk-action regressions: php tests/admin_bulk_http.php
 * Optional Chrome: SAGIPBRO_ADMIN_BULK_BROWSER=1. Requires CREATE/DROP DATABASE,
 * DOM, proc_open, and MySQL. Never selects the application's configured database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../api/bulk_operations.php';
$project = dirname(__DIR__);
$name = 'sagipbro_global_bulk_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-global-bulk-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $address = ''; $identities = []; $checks = 0; $failed = false;
$modules = sagipbroBulkModules();

function gbCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function gbInsert(PDO $db, string $table, array $values): int
{
    $query = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $query->execute(array_values($values)); return (int) $db->lastInsertId();
}

function gbRecord(string $table, int $id): ?array
{
    global $db;
    $query = $db->prepare('SELECT * FROM `' . $table . '` WHERE id=?'); $query->execute([$id]); return $query->fetch() ?: null;
}

function gbSnapshot(): array
{
    global $db, $modules;
    $result = [];
    foreach (array_unique(array_merge(array_column($modules, 'table'), ['activity_logs', 'evacuees', 'households', 'user_sessions'])) as $table) $result[$table] = $db->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll();
    return $result;
}

function gbRequest(string $route, string $method = 'GET', ?array $data = null, ?string $role = 'admin', bool $csrf = true): array
{
    global $address, $identities;
    $headers = ['Accept: application/json', 'Connection: close'];
    if ($role !== null) $headers[] = 'Cookie: ' . $identities[$role]['cookie'];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        if ($csrf && $role !== null) $headers[] = 'X-CSRF-Token: ' . $identities[$role]['csrf'];
    }
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    if ($data !== null) $options['content'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $stream = @fopen($address . '/' . ltrim($route, '/'), 'rb', false, stream_context_create(['http' => $options]));
    if (!$stream) throw new LogicException('Private global-bulk server unavailable.');
    $body = (string) stream_get_contents($stream); $meta = stream_get_meta_data($stream); fclose($stream);
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $meta['wrapper_data'][0] ?? '', $status);
    gbCheck(!preg_match('/SQLSTATE|Fatal error|Warning:|Stack trace:|PRIVATE_AUDIT_FAILURE/i', $body), 'Bulk responses do not expose technical/audit internals.');
    return ['status' => (int) ($status[1] ?? 0), 'body' => $body, 'json' => json_decode($body, true)];
}

function gbApi(array $data, int $expected = 200, ?string $role = 'admin', bool $csrf = true): array
{
    $response = gbRequest('api/bulk.php', 'POST', $data, $role, $csrf);
    gbCheck($response['status'] === $expected, 'Bulk ' . json_encode([$data['module'] ?? null, $data['action'] ?? null]) . ' expected ' . $expected . ', got ' . $response['status'] . ': ' . substr($response['body'], 0, 250));
    if ($expected !== 419) gbCheck(is_array($response['json']), 'Bulk API returns valid JSON.');
    return $response['json'] ?? [];
}

function gbRun(string $module, string $action, array $ids, array $changed): array
{
    global $db, $modules;
    sort($changed, SORT_NUMERIC); $selected = $ids; sort($selected, SORT_NUMERIC);
    $before = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM activity_logs')->fetchColumn();
    $result = gbApi(['module' => $module, 'action' => $action, 'ids' => $ids]);
    gbCheck($result['module'] === $module && $result['action'] === $action && $result['selected_count'] === count($ids) && $result['affected_count'] === count($changed) && $result['unchanged_count'] === count($ids) - count($changed) && $result['failed_ids'] === [], 'Bulk result accurately identifies the module/action and changed/no-op counts.');
    gbCheck($result['affected_ids'] === $changed && $result['succeeded_ids'] === $changed, 'Success identifies exactly the changed IDs.');
    $logs = $db->query('SELECT * FROM activity_logs WHERE id>' . $before . ' ORDER BY id')->fetchAll();
    gbCheck(count($logs) === count($changed) + 1, 'Every changed record and one summary are audited atomically.');
    $summary = array_pop($logs); $details = json_decode($summary['details'], true);
    gbCheck($summary['action'] === 'bulk_' . $action && $summary['entity_type'] === $modules[$module]['entity'] && $summary['entity_id'] === null && $details['module'] === $module && $details['selected_ids'] === $selected && $details['affected_ids'] === $changed && $details['affected_count'] === count($changed) && $details['selected_count'] === count($ids), 'Summary audit preserves exact operation and selected/changed IDs.');
    gbCheck(array_map('intval', array_column($logs, 'entity_id')) === $changed, 'Per-record audits reference only the changed records.');
    foreach ($logs as $log) {
        $detail = json_decode($log['details'], true);
        gbCheck($log['action'] === $action && $log['entity_type'] === $modules[$module]['entity'] && $detail['module'] === $module && isset($detail['previous_status']) && (isset($detail['status']) || ($detail['deleted'] ?? false)), 'Per-record audit contains module and state metadata.');
    }
    foreach (array_merge($logs, [$summary]) as $log) gbCheck(!str_contains($log['details'], 'PRIVATE_') && !preg_match('/"(?:password_hash|body|contact|email|full_name|first_name|last_name|message|title)"/', $log['details']), 'New bulk audits contain no record titles, contact details, personal content or passwords.');
    return $result;
}

function gbConflict(string $module, string $action, array $ids, array $blocked): void
{
    $before = gbSnapshot(); $result = gbApi(['module' => $module, 'action' => $action, 'ids' => $ids], 409);
    sort($blocked, SORT_NUMERIC);
    gbCheck($result['affected_count'] === 0 && $result['succeeded_ids'] === [] && $result['rolled_back'] === true && $result['failed_ids'] === $blocked, 'Conflict identifies blocked IDs and explicitly reports zero partial successes.');
    gbCheck(gbSnapshot() === $before, 'A blocked selection rolls back all records, sessions, related history, and audits.');
}

function gbBrowser(string $directory, array $environment, array $cases): void
{
    global $address;
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    gbCheck(is_file($chrome), 'Set SAGIPBRO_CHROME_BINARY for optional browser checks.');
    $script = 'const cases=' . json_encode($cases, JSON_THROW_ON_ERROR) . ';' . <<<'JS'
const output=document.querySelector('pre'),frame=document.querySelector('iframe'); let checks=0,operations=[];
const check=(value,message)=>{checks++;if(!value)throw new Error(message)};
const settle=(ms=100)=>new Promise(resolve=>setTimeout(resolve,ms));
const until=async(condition,label)=>{for(let n=0;n<140;n++){if(condition())return;await settle(50)}throw new Error('Timed out: '+label)};
const load=async route=>{await new Promise(resolve=>{frame.onload=resolve;frame.src=route});await settle()};
let doc,win,table,modal,form;
const boxes=()=>[...table.querySelectorAll('[data-bulk-select]')];
const checked=()=>boxes().filter(box=>box.checked).map(box=>Number(box.dataset.bulkSelect));
const toolbar=()=>doc.querySelector('[data-bulk-toolbar]');
const header=()=>table.querySelector('[data-bulk-select-all]');
const count=()=>Number((doc.querySelector('[data-bulk-count]').textContent.match(/\d+/)||[0])[0]);
const ready=async()=>until(()=>table?.getAttribute('aria-busy')==='false'&&boxes().length>0,'real '+table?.id+' rows');
const clear=label=>check(toolbar().hidden&&checked().length===0&&count()===0&&!header().checked&&!header().indeterminate,label);
const choose=(amount=2)=>{boxes().slice(0,amount).forEach(box=>{if(!box.checked)box.click()});check(count()===amount&&!toolbar().hidden,'Current-page selected count')};
const change=async(control,value)=>{control.value=value;control.dispatchEvent(new win.Event(control.type==='search'?'input':'change',{bubbles:true}));await settle(350);await ready()};
const modalEvent=async(name,action)=>{let happened=false;modal.addEventListener(name,()=>{happened=true},{once:true});action();await until(()=>happened,name)};
(async()=>{
 frame.style.width='1440px';frame.style.height='900px';
 for(const fixture of cases){
  await load(fixture.route);doc=frame.contentDocument;win=frame.contentWindow;table=doc.getElementById(fixture.table);await ready();
  const search=doc.querySelector(`[data-table-search="#${fixture.table}"]`);await change(search,'GBUI');
  modal=doc.querySelector('[data-bulk-modal]');form=modal.querySelector('[data-bulk-form]');
  const component=win.SagipbroBulk.get('#'+fixture.table);
  check(component&&boxes().length===10,fixture.module+' exposes one shared component and ten current-page checkboxes');
  check(!win.globalBulkXss,fixture.module+' escapes database text');
  clear(fixture.module+' toolbar initially hidden');choose(1);
  check(header().indeterminate&&!header().checked,fixture.module+' partial selection is indeterminate');
  header().click();check(count()===10&&checked().length===10&&header().checked,fixture.module+' Select All never includes another page');
  doc.querySelector('[data-bulk-clear]').click();clear(fixture.module+' Clear Selection resets header/count');
  choose(1);search.dispatchEvent(new win.Event('input',{bubbles:true}));clear(fixture.module+' search clears selection before debounce');await settle(400);await ready();
  const filter=doc.getElementById(fixture.filter);choose(1);filter.value=fixture.value;filter.dispatchEvent(new win.Event('change',{bubbles:true}));clear(fixture.module+' filter clears selection synchronously');await ready();
  choose(1);doc.querySelector('[aria-label="Next"]').click();clear(fixture.module+' page change clears selection');await ready();
  check(boxes().length===3,fixture.module+' Next loads only the last three matching records');header().click();check(checked().length===3,fixture.module+' page-two Select All stays on page two');
  const size=doc.getElementById(fixture.table+'PageSize');size.value='25';size.dispatchEvent(new win.Event('change',{bubbles:true}));clear(fixture.module+' size change clears selection');await ready();check(boxes().length===13,fixture.module+' larger page uses actual matching data');await change(size,'10');
  const nativeFetch=win.fetch.bind(win);let mutations=[];win.fetch=(url,options={})=>{if(options.method&&options.method!=='GET')mutations.push(JSON.parse(options.body));return nativeFetch(url,options)};
  // The geometry-only iframe disables transitions, avoiding virtual-time
  // compositor freezes; Bootstrap show/hide events and all controls remain real.
  const style=doc.createElement('style');style.textContent='.modal.fade .modal-dialog,.modal-backdrop.fade{transition:none!important}';doc.head.append(style);
  for(const width of [1440,390,320]){
   frame.style.width=width+'px';await settle();choose(2);
   const action=doc.querySelector(`[data-bulk-action="${fixture.action}"]`);check(action&&!action.disabled,fixture.module+' exposes only a supported enabled action');
   await modalEvent('shown.bs.modal',()=>action.click());
   check(/\b2\b/.test(modal.querySelector('[data-bulk-message]').textContent),fixture.module+' confirmation names the exact selected count');
   const rect=modal.querySelector('.modal-content').getBoundingClientRect(),button=modal.querySelector('[data-bulk-confirm]').getBoundingClientRect();
   check(win.innerWidth===width&&doc.documentElement.scrollWidth<=width+1,fixture.module+' '+width+'px has no page-wide overflow');
   check(rect.left>=-1&&rect.right<=width+1&&rect.top>=-1&&rect.bottom<=win.innerHeight+1,fixture.module+' '+width+'px confirmation stays inside viewport');
   check(button.height>=40&&button.left>=rect.left&&button.right<=rect.right+1&&button.bottom<=rect.bottom+1,fixture.module+' '+width+'px confirmation action remains visible/tappable');
   check(toolbar().scrollWidth<=toolbar().clientWidth+1,fixture.module+' '+width+'px toolbar wraps within its card');
   await modalEvent('hidden.bs.modal',()=>modal.querySelector('.modal-footer [data-bs-dismiss="modal"]').click());
   check(mutations.length===0,fixture.module+' Cancel never mutates a record');doc.querySelector('[data-bulk-clear]').click();
  }
  frame.style.width='1440px';await settle();choose(2);const ids=checked();
  await modalEvent('shown.bs.modal',()=>doc.querySelector(`[data-bulk-action="${fixture.action}"]`).click());
  await modalEvent('hidden.bs.modal',()=>form.requestSubmit(modal.querySelector('[data-bulk-confirm]')));await ready();
  check(mutations.length===1&&mutations[0].module===fixture.module&&mutations[0].action===fixture.action&&JSON.stringify(mutations[0].ids)===JSON.stringify(ids),fixture.module+' sends one confirmed mutation containing only selected IDs');
  clear(fixture.module+' success clears selection');check(search.value==='GBUI'&&filter.value===fixture.value,fixture.module+' reload preserves search and filters');
  operations.push({module:fixture.module,action:fixture.action,ids});
 }
 for(const route of ['/pages/reports/index.php','/pages/activity/index.php']){await load(route);check(!frame.contentDocument.querySelector('[data-bulk-select-all],[data-bulk-toolbar],[data-bulk-modal]'),route+' has no unsupported source/audit destructive actions')}
 await load('/__global_bulk_official');check(!frame.contentDocument.querySelector('[data-bulk-select-all],[data-bulk-toolbar],[data-bulk-modal]'),'Officials do not receive administrator-only bulk controls');
 output.textContent=JSON.stringify({ok:true,checks,operations});
})().catch(error=>{output.textContent=JSON.stringify({ok:false,checks,error:error.message})});
JS;
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe title="Global bulk management"></iframe><script>' . $script . '</script>');
    $process = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=110000', '--dump-dom', $address . '/__global_bulk_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, dirname(__DIR__), $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    gbCheck(is_resource($process), 'Private Chrome process starts.'); fclose($pipes[0]);
    $deadline = microtime(true) + 55;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($process)['running']) proc_terminate($process); proc_close($process);
    preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/dom.html'), $match);
    $result = json_decode(html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    gbCheck(($result['ok'] ?? false) === true, 'Global bulk browser: ' . json_encode($result));
    $statuses = ['resources' => 'Inactive', 'centers' => 'Closed', 'distributions' => 'Completed', 'residents' => 'Inactive', 'volunteers' => 'Inactive', 'announcements' => 'Archived', 'messages' => 'Read', 'users' => 'Inactive'];
    foreach ($result['operations'] as $operation) foreach ($operation['ids'] as $id) gbCheck(gbRecord(sagipbroBulkModules()[$operation['module']]['table'], (int) $id)['status'] === $statuses[$operation['module']], 'Browser confirmed mutation persists exactly the selected module records.');
    fwrite(STDOUT, 'PASS: ' . $result['checks'] . " all-eight-module desktop/mobile Chrome assertions.\n");
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306'; $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    gbCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Safe private database connection settings.');
    $db = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    gbCheck((bool) preg_match('/\Asagipbro_global_bulk_[a-f0-9]{20}\z/', $name), 'Safe random fixture database identifier.');
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true; $db->exec('USE `' . $name . '`'); $db->exec("SET time_zone='+08:00'");
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) { $sql = trim($sql); if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE|USE)\b/i', $sql)) $db->exec($sql); }
    gbCheck(mkdir($directory, 0700), 'Private session directory created.'); putenv('SAGIPBRO_SESSION_PATH=' . $directory); require_once $project . '/config/session.php';
    foreach (['admin', 'official', 'volunteer', 'resident'] as $role) {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_id(''); session_start(); }
        $id = gbInsert($db, 'users', ['full_name' => 'PRIVATE_ACTOR_' . $role, 'username' => 'global_bulk_' . $role, 'password_hash' => 'TEST-NOT-LOGIN', 'role' => $role]);
        $_SESSION = ['user_id' => $id, 'role' => $role, 'full_name' => 'PRIVATE_ACTOR_' . $role, 'username' => 'global_bulk_' . $role];
        $identities[$role] = ['id' => $id, 'session' => session_id(), 'cookie' => session_name() . '=' . session_id(), 'csrf' => csrfToken()]; session_write_close();
    }
    $actor = $identities['admin']['id'];
    $userActive = gbInsert($db, 'users', ['full_name' => 'PRIVATE_USER_TARGET', 'username' => 'private_target', 'email' => 'PRIVATE_EMAIL@example.test', 'password_hash' => 'PRIVATE_PASSWORD_HASH', 'role' => 'official']);
    $userInactive = gbInsert($db, 'users', ['full_name' => 'PRIVATE_USER_INACTIVE', 'username' => 'private_inactive', 'password_hash' => 'PRIVATE_PASSWORD_HASH', 'role' => 'resident', 'status' => 'Inactive']);
    $household = gbInsert($db, 'households', ['household_no' => 'PRIVATE-HOUSEHOLD', 'address' => 'PRIVATE_ADDRESS', 'barangay' => 'Bonuan Binloc']);
    $resident = gbInsert($db, 'residents', ['household_id' => $household, 'first_name' => 'PRIVATE_FIRST', 'last_name' => 'PRIVATE_LAST', 'sex' => 'Other', 'contact_no' => 'PRIVATE_CONTACT', 'address' => 'PRIVATE_ADDRESS']);
    $residentInactive = gbInsert($db, 'residents', ['first_name' => 'PRIVATE_INACTIVE', 'last_name' => 'PRIVATE_LAST', 'sex' => 'Other', 'status' => 'Inactive']);
    $db->exec('UPDATE households SET head_resident_id=' . $resident . ' WHERE id=' . $household);
    $resource = gbInsert($db, 'resources', ['name' => 'PRIVATE_RESOURCE', 'category' => 'Food packs', 'unit' => 'packs', 'stock' => 37, 'low_stock_threshold' => 5]);
    $resourceInactive = gbInsert($db, 'resources', ['name' => 'PRIVATE_INACTIVE_RESOURCE', 'category' => 'Food packs', 'unit' => 'packs', 'stock' => 15, 'status' => 'Inactive']);
    $center = gbInsert($db, 'evacuation_centers', ['name' => 'PRIVATE_SAFE_CENTER', 'address' => 'Bonuan Binloc', 'capacity' => 100]);
    $centerClosed = gbInsert($db, 'evacuation_centers', ['name' => 'PRIVATE_CLOSED_CENTER', 'address' => 'Bonuan Binloc', 'capacity' => 100, 'status' => 'Closed']);
    $centerOccupied = gbInsert($db, 'evacuation_centers', ['name' => 'PRIVATE_OCCUPIED_CENTER', 'address' => 'Bonuan Binloc', 'capacity' => 100, 'occupants' => 1]);
    $centerActive = gbInsert($db, 'evacuation_centers', ['name' => 'PRIVATE_STALE_COUNT_CENTER', 'address' => 'Bonuan Binloc', 'capacity' => 100]);
    gbInsert($db, 'evacuees', ['resident_id' => $resident, 'center_id' => $centerActive, 'name' => 'PRIVATE_EVACUEE']);
    gbInsert($db, 'evacuees', ['resident_id' => $resident, 'center_id' => $center, 'name' => 'PRIVATE_CHECKED_OUT', 'checked_out_at' => '2020-01-01 12:00:00']);
    $distributionBase = ['resource_id' => $resource, 'recipient_resident_id' => $resident, 'recipient_name' => 'PRIVATE_RECIPIENT', 'quantity' => 3, 'distributed_by' => $userActive];
    $distribution = gbInsert($db, 'distributions', ['status' => 'Pending review'] + $distributionBase); $completed = gbInsert($db, 'distributions', $distributionBase);
    $volunteerBase = ['contact' => 'PRIVATE_PHONE', 'email' => 'PRIVATE_EMAIL@example.test', 'availability' => 'Weekdays', 'skills' => 'First aid'];
    $volunteer = gbInsert($db, 'volunteers', ['full_name' => 'PRIVATE_VOLUNTEER'] + $volunteerBase);
    $volunteerInactive = gbInsert($db, 'volunteers', ['full_name' => 'PRIVATE_INACTIVE', 'status' => 'Inactive'] + $volunteerBase);
    $deployed = gbInsert($db, 'volunteers', ['full_name' => 'PRIVATE_DEPLOYED', 'status' => 'Deployed', 'assignment' => 'PRIVATE_ONGOING_OPERATION'] + $volunteerBase);
    $announcementBase = ['body' => 'PRIVATE_BODY', 'created_by' => $userActive, 'priority' => 'Urgent', 'published_at' => '2020-01-01 12:00:00'];
    $announcement = gbInsert($db, 'announcements', ['title' => 'PRIVATE_PUBLISHED', 'status' => 'Published'] + $announcementBase);
    $announcementDraft = gbInsert($db, 'announcements', ['title' => 'PRIVATE_DRAFT'] + $announcementBase);
    $announcementArchived = gbInsert($db, 'announcements', ['title' => 'PRIVATE_ARCHIVED', 'status' => 'Archived'] + $announcementBase);
    $messageBase = ['name' => 'PRIVATE_SENDER', 'email' => 'PRIVATE_EMAIL@example.test', 'message' => 'PRIVATE_MESSAGE'];
    $message = gbInsert($db, 'contact_messages', ['subject' => 'PRIVATE_UNREAD'] + $messageBase);
    $messageRead = gbInsert($db, 'contact_messages', ['subject' => 'PRIVATE_READ', 'status' => 'Read'] + $messageBase);
    foreach ([$actor, $userActive, $userInactive, $identities['official']['id']] as $id) gbInsert($db, 'user_sessions', ['session_hash' => hash('sha256', 'global-bulk-' . $id), 'user_id' => $id, 'last_seen_at' => date('Y-m-d H:i:s')]);
    $inventoryBefore = [gbRecord('resources', $resource), gbRecord('resources', $resourceInactive)]; $distributionBefore = gbRecord('distributions', $distribution); $completedBefore = gbRecord('distributions', $completed);
    $environment = getenv(); foreach (['SAGIPBRO_DB_HOST' => $host, 'SAGIPBRO_DB_PORT' => $port, 'SAGIPBRO_DB_USER' => $user === false ? 'root' : $user, 'SAGIPBRO_DB_PASSWORD' => $password === false ? '' : $password, 'SAGIPBRO_DB_NAME' => $name, 'SAGIPBRO_SESSION_PATH' => $directory, 'SAGIPBRO_BASE_URL' => ''] as $key => $value) $environment[$key] = $value; unset($environment['PHP_CLI_SERVER_WORKERS']);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $listen = stream_socket_get_name($socket, false); fclose($socket); $address = 'http://' . $listen;
    $router = '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);if($path==="/__global_bulk_browser"){setcookie(' . var_export(session_name(), true) . ',' . var_export($identities['admin']['session'], true) . ',["path"=>"/","httponly"=>true,"samesite"=>"Lax"]);readfile(' . var_export($directory . '/browser.html', true) . ');return true;}if($path==="/__global_bulk_official"){require_once ' . var_export($project . '/config/session.php', true) . ';session_write_close();$_SESSION["role"]="official";$_SESSION["user_id"]=' . $identities['official']['id'] . ';$path="/pages/resources/index.php";}if(preg_match("~^/pages/(?:resources|evacuation|distribution|residents|volunteers|announcements|messages|users|reports|activity)/index\\.php$~",$path)){$target=' . var_export($project, true) . '.$path;chdir(dirname($target));ob_start();require $target;header_remove("X-Frame-Options");ob_end_flush();return true;}return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    gbCheck(is_resource($server), 'Private global-bulk PHP server starts.'); fclose($pipes[0]); $deadline = microtime(true) + 5;
    do { $probe = @stream_socket_client('tcp://' . $listen, $errno, $error, .1); if ($probe) { fclose($probe); break; } usleep(25000); } while (microtime(true) < $deadline);

    $idsByModule = ['resources' => $resource, 'centers' => $center, 'distributions' => $distribution, 'residents' => $resident, 'volunteers' => $volunteer, 'announcements' => $announcement, 'messages' => $message, 'users' => $userActive];
    $before = gbSnapshot();
    foreach ($modules as $module => $configuration) {
        $data = ['module' => $module, 'action' => $configuration['actions'][0], 'ids' => [$idsByModule[$module]]];
        gbApi($data, 401, null); foreach (['official', 'volunteer', 'resident'] as $role) gbApi($data, 403, $role); gbApi($data, 419, 'admin', false);
        gbApi(['module' => $module, 'action' => 'unsupported_delete', 'ids' => $data['ids']], 422);
        gbApi($data + ['extra' => 'ignored'], 404, 'admin', true);
    }
