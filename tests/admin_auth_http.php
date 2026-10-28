<?php
declare(strict_types=1);

/** php tests/admin_auth_http.php: isolated database, sessions, and HTTP server.
 * Set SAGIPBRO_AUTH_BROWSER=1 for the user account status button browser checks.
 * Set SAGIPBRO_PASSWORD_LIVE_TEST=1 for password writes with the real hash-range API.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../config/config.php';
$project = dirname(__DIR__);
$name = 'sagipbro_auth_test_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-auth-' . bin2hex(random_bytes(8));
$db = null; $created = false; $server = null; $address = ''; $checks = 0; $failed = false;

function authCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function authRequest(string $route, array $identity = [], string $method = 'GET', ?array $data = null, array $extraHeaders = []): array
{
    global $address;
    $headers = array_merge(['Connection: close'], $extraHeaders);
    if (!empty($identity['cookie'])) $headers[] = 'Cookie: ' . $identity['cookie'];
    $json = in_array($method, ['PUT', 'DELETE'], true);
    if ($data !== null) {
        $headers[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
        if (isset($identity['csrf'])) $headers[] = 'X-CSRF-Token: ' . $identity['csrf'];
    }
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    if ($data !== null) $options['content'] = $json ? json_encode($data, JSON_THROW_ON_ERROR) : http_build_query($data);
    $stream = @fopen($address . '/' . $route, 'rb', false, stream_context_create(['http' => $options]));
    if (!$stream) throw new LogicException('Isolated authentication server unavailable.');
    $body = (string) stream_get_contents($stream); $meta = stream_get_meta_data($stream); fclose($stream);
    $headers = implode("\n", $meta['wrapper_data'] ?? []);
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $headers, $status);
    preg_match('/^Location:\s*(.*)$/mi', $headers, $location);
    preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;\s]+)/mi', $headers, $cookie);
    authCheck(!preg_match('/SQLSTATE|Fatal error|Warning:|Stack trace:/i', $body), 'Responses must not expose PHP/database internals.');
    return ['status' => (int) ($status[1] ?? 0), 'body' => $body, 'location' => trim($location[1] ?? ''), 'cookie' => $cookie[1] ?? ''];
}

function authLogin(string $username, string $password = 'Private-test-password-42', array $extraHeaders = []): array
{
    $page = authRequest('login.php');
    authCheck($page['status'] === 200, 'The anonymous login must load.');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $token);
    authCheck(isset($token[1]) && $page['cookie'] !== '', 'Login must issue a session and CSRF token.');
    $response = authRequest('actions/auth/login.php', ['cookie' => $page['cookie']], 'POST', [
        'username' => $username, 'password' => $password, 'not_robot' => '1', 'csrf_token' => $token[1]
    ], $extraHeaders);
    return $response + ['csrf' => $token[1], 'initial_cookie' => $page['cookie']];
}

function authFixtureSession(int $id, string $role = 'admin', bool $registered = true, bool $revoked = false): array
{
    global $db;
    session_id(''); session_start();
    $_SESSION = ['user_id' => $id, 'role' => $role, 'full_name' => 'Auth fixture', 'username' => 'auth_fixture'];
    $identity = ['cookie' => session_name() . '=' . session_id(), 'csrf' => csrfToken()];
    if ($registered) {
        $statement = $db->prepare('INSERT INTO user_sessions (session_hash, user_id, last_seen_at, signed_out_at) VALUES (?, ?, NOW(), ?)');
        $statement->execute([hash('sha256', session_id()), $id, $revoked ? date('Y-m-d H:i:s') : null]);
    }
    session_write_close();
    return $identity;
}

function authSetupAdmin(string $username, string $password, array $environment): array
{
    global $project;
    $environment['SAGIPBRO_ADMIN_USERNAME'] = $username;
    $environment['SAGIPBRO_ADMIN_PASSWORD'] = $password;
    $process = proc_open([PHP_BINARY, $project . '/actions/auth/setup_admin.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $environment,
        ['bypass_shell' => true, 'create_new_console' => false]);
    authCheck(is_resource($process), 'Start CLI administrator setup against the private database.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $output];
}

function authStatusBrowser(array $operator, int $targetId, array $environment): void
{
    global $project, $directory, $address;
    $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    authCheck(is_file($chrome), 'Chrome is required for optional account status checks.');
    file_put_contents($directory . '/browser-cookie.txt', substr($operator['cookie'], strpos($operator['cookie'], '=') + 1));
    $script = 'const targetId=' . $targetId . ';' . <<<'JS'
const output = document.getElementById('result'), frame = document.getElementById('page');
let checks = 0;
const check = (condition, message) => { ++checks; if (!condition) throw new Error(message); };
const until = async (condition, message) => { for (let i=0;i<160;i++) { if (condition()) return; await new Promise(resolve=>setTimeout(resolve,50)); } throw new Error(message); };
const load = () => new Promise(resolve => { frame.onload=resolve; frame.src='/pages/users/index.php'; });
(async () => {
    await load();
    let doc=frame.contentDocument, page=frame.contentWindow;
    const trigger=()=>doc.querySelector('[data-user-delete="'+targetId+'"]');
    const row=()=>trigger()?.closest('tr');
    await until(()=>!!trigger(),'User status buttons did not load');
    check(!doc.querySelector('#usersTablePageSize') && !doc.querySelector('.server-pagination-footer select'),'Admin pagination has no records-per-page selector');
    let mutations=0, failNext=false, failedRequest=false;
    const originalFetch=page.fetch.bind(page);
    page.fetch=async (url, options={})=>{
        if (String(url).includes('/api/users.php') && options.method && options.method !== 'GET') {
            mutations++;
            if (failNext) { failNext=false; failedRequest=true; return new page.Response(JSON.stringify({error:'Temporary test failure'}),{status:503,headers:{'Content-Type':'application/json'}}); }
        }
        return originalFetch(url,options);
    };
    const modal=doc.getElementById('deactivateUserModal'), form=doc.getElementById('deactivateUserForm');
    const submit=()=>form.querySelector('[type="submit"]'), cancel=()=>form.querySelector('.modal-footer [data-bs-dismiss]');
    const modalEvent=(event, action)=>new Promise(resolve=>{ modal.addEventListener(event,resolve,{once:true}); action(); });
    const status=async()=>{const result=await (await originalFetch('/api/users.php?id='+targetId)).json(); return result.data[0].status;};
    await modalEvent('shown.bs.modal',()=>trigger().click());
    check(cancel().textContent==='Keep active','Enabled accounts offer Keep active');
    await modalEvent('hidden.bs.modal',()=>cancel().click());
    check(mutations===0 && await status()==='Active','Keep active cancels without changing the account');
    await modalEvent('shown.bs.modal',()=>trigger().click());
    await modalEvent('hidden.bs.modal',()=>submit().click());
    await until(()=>row()?.dataset.status==='Inactive','Deactivation did not update the row');
    check(await status()==='Inactive' && trigger().title==='Enable','Disabled accounts offer Enable');
    await modalEvent('shown.bs.modal',()=>trigger().click());
    check(form.querySelector('.modal-title').textContent==='Enable user account?' && submit().textContent.includes('Enable account') && cancel().textContent==='Cancel','Enable dialog has the correct action and cancel labels');
    await modalEvent('hidden.bs.modal',()=>cancel().click());
    check(mutations===1 && await status()==='Inactive','Cancelling enable leaves the account disabled');
    await modalEvent('shown.bs.modal',()=>trigger().click());
    failNext=true; submit().click();
    await until(()=>failedRequest && !submit().disabled,'Failed enable did not restore the button');
    check(modal.classList.contains('show') && await status()==='Inactive','Failed enable stays open and leaves database status unchanged');
    await modalEvent('hidden.bs.modal',()=>submit().click());
    await until(()=>row()?.dataset.status==='Active','Enable did not update the row');
    check(await status()==='Active' && !submit().disabled,'Retry enables the account and restores the button');
    await modalEvent('shown.bs.modal',()=>trigger().click());
    check(cancel().textContent==='Keep active' && submit().textContent.includes('Deactivate account'),'Reopening resets the dialog for an enabled account');
    await modalEvent('hidden.bs.modal',()=>cancel().click());
    await load(); doc=frame.contentDocument; page=frame.contentWindow;
    await until(()=>row()?.dataset.status==='Active','Enabled status did not persist after reload');
    check(trigger().title==='Deactivate','Enabled status and action survive a page reload');
    const expiry=doc.getElementById('sessionExpiryModal'), stay=expiry.querySelector('[data-session-continue]');
    const nativeFetch=page.fetch.bind(page);
    let activityPosts=0, failRenewal=false, absoluteWarning=false;
    page.fetch=async (url, options={})=>{
        if (String(url).includes('/api/session.php')) {
            if (options.method==='POST') {
                activityPosts++;
                if(failRenewal){ failRenewal=false; return new page.Response(JSON.stringify({error:'Temporary renewal failure'}),{status:503,headers:{'Content-Type':'application/json'}}); }
            } else if (absoluteWarning) {
                const result=await (await nativeFetch(url,options)).json();
                result.absolute_expires_at=result.server_now+90;
                return new page.Response(JSON.stringify(result),{status:200,headers:{'Content-Type':'application/json'}});
            }
        }
        return nativeFetch(url,options);
    };
    const beforeRenewal=await (await nativeFetch('/api/session.php')).json();
    await page.fetch('/api/session.php',{method:'GET'});
    check(activityPosts===0,'Background status reads never send activity renewal');
    page.sagipbroSession.timing.idle_expires_at=page.sagipbroSession.timing.server_now+90;
    await until(()=>expiry.classList.contains('show') && !page.bootstrap.Modal.getInstance(expiry)._isTransitioning,'Idle warning did not open');
    check(!stay.disabled && expiry.querySelector('[data-session-countdown]').textContent,'Idle warning offers Stay signed in and a countdown');
    failRenewal=true; stay.click();
    await until(()=>activityPosts===1 && !stay.disabled,'Failed renewal did not restore Stay signed in');
    check(expiry.classList.contains('show') && !expiry.querySelector('[data-session-error]').hidden,'Failed renewal keeps the warning visible with a retry message');
    await new Promise(resolve=>{expiry.addEventListener('hidden.bs.modal',resolve,{once:true});stay.click();});
    const renewed=await (await nativeFetch('/api/session.php')).json();
    check(activityPosts===2 && renewed.idle_expires_at-renewed.server_now>=1198,'Stay signed in renews the server deadline after retry');
    check(renewed.absolute_expires_at===beforeRenewal.absolute_expires_at,'Browser renewal never extends the eight-hour deadline');
    absoluteWarning=true;
    doc.dispatchEvent(new page.Event('visibilitychange'));
    await until(()=>expiry.classList.contains('show') && stay.disabled,'Absolute-limit warning did not disable renewal');
    check(expiry.querySelector('#sessionExpiryMessage').textContent.includes('eight-hour'),'The absolute warning explains that a new sign-in is required');
    output.textContent=JSON.stringify({ok:true,checks});
})().catch(error=>{output.textContent=JSON.stringify({ok:false,checks,error:error.message});});
JS;
    file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe id="page" style="width:1280px;height:900px"></iframe><script>' . $script . '</script>');
    $browser = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=20000', '--dump-dom', $address . '/__auth_browser'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/browser-dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']],
        $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    authCheck(is_resource($browser), 'Start headless account status checks.'); fclose($pipes[0]);
    $deadline = microtime(true) + 45;
    while (proc_get_status($browser)['running'] && microtime(true) < $deadline) usleep(50000);
    if (proc_get_status($browser)['running']) proc_terminate($browser);
    proc_close($browser);
    preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/browser-dom.html'), $result);
    $details = json_decode(html_entity_decode($result[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    if ($details === null) $details = ['error' => 'Browser did not produce a test result.', 'log' => substr((string) file_get_contents($directory . '/chrome.log'), -1800)];
    authCheck(($details['ok'] ?? false) === true, 'Account status browser checks failed: ' . json_encode($details));
    fwrite(STDOUT, 'PASS: ' . $details['checks'] . " account status browser checks.\n");
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    authCheck(ctype_digit($port) && strpbrk($host, ";\r\n") === false, 'Invalid test database host/port.');
    $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    $db = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user === false ? 'root' : $user, $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 3]);
    $db->exec("SET time_zone='+08:00'");
    $db->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created = true;
    $db->exec('USE `' . $name . '`');
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $sql) {
        $sql = trim($sql);
        if ($sql !== '' && !preg_match('/\A(?:CREATE\s+DATABASE\b|USE\b)/i', $sql)) $db->exec($sql);
    }
    $ids = [];
    $insert = $db->prepare('INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, ?)');
    $hash = password_hash('Private-test-password-42', PASSWORD_DEFAULT);
    foreach (['admin', 'official', 'volunteer', 'resident', 'inactive'] as $role) {
        $insert->execute(['Auth ' . $role, 'auth_' . $role, $role . '@example.test', $hash, $role === 'inactive' ? 'admin' : $role, $role === 'inactive' ? 'Inactive' : 'Active']);
        $ids[$role] = (int) $db->lastInsertId();
    }
    // Exercise upgrading an existing session table and preserving its activity history.
    require_once $project . '/database/session_security_migration.php';
    $db->exec('ALTER TABLE user_sessions DROP COLUMN last_activity_at');
    $legacyHash = hash('sha256', 'private-legacy-session');
    $db->exec("INSERT INTO user_sessions (session_hash,user_id,created_at,last_seen_at) VALUES ('{$legacyHash}',{$ids['admin']},DATE_SUB(NOW(),INTERVAL 1 HOUR),DATE_SUB(NOW(),INTERVAL 25 MINUTE))");
    migrateSessionSecurity($db);
    $legacySession = $db->query("SELECT * FROM user_sessions WHERE session_hash='{$legacyHash}'")->fetch();
    authCheck($legacySession['last_activity_at'] === $legacySession['last_seen_at'], 'Migration preserves legacy activity timestamps instead of renewing sessions.');
    migrateSessionSecurity($db);
    authCheck($db->query("SELECT * FROM user_sessions WHERE session_hash='{$legacyHash}'")->fetch() === $legacySession, 'Session migration can be rerun without changing records.');
    $db->exec("DELETE FROM user_sessions WHERE session_hash='{$legacyHash}'");
    authCheck(mkdir($directory, 0700), 'Create private session directory.');
    putenv('SAGIPBRO_SESSION_PATH=' . $directory);
    require_once $project . '/config/session.php'; session_write_close();
    $environment = getenv();
    $environment['SAGIPBRO_DB_NAME'] = $name; $environment['SAGIPBRO_SESSION_PATH'] = $directory; $environment['SAGIPBRO_BASE_URL'] = '';
    // Exercise the existing local CAPTCHA path, without external requests.
    $environment['SAGIPBRO_RECAPTCHA_SITE_KEY'] = ''; $environment['SAGIPBRO_RECAPTCHA_SECRET'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    authCheck(is_resource($reservation), 'Reserve a private HTTP port.');
    $listen = stream_socket_get_name($reservation, false); fclose($reservation); $address = 'http://' . $listen;
    // Allow the real Users page in this private test iframe; production keeps DENY.
    $router = '<?php $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($path === "/__auth_browser") { setcookie("PHPSESSID", trim(file_get_contents(__DIR__ . "/browser-cookie.txt")), ["path"=>"/", "httponly"=>true,"samesite"=>"Lax"]); readfile(__DIR__ . "/browser.html"); return true; } if ($path === "/pages/users/index.php") { $target = ' . var_export($project . '/pages/users/index.php', true) . '; chdir(dirname($target)); ob_start(); require $target; header_remove("X-Frame-Options"); ob_end_flush(); return true; } return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', $listen, '-t', $project, $directory . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']],
        $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    authCheck(is_resource($server), 'Start a private PHP server.'); fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        $socket = @stream_socket_client('tcp://' . $listen, $errno, $error, 0.1);
        if ($socket) { fclose($socket); break; }
        usleep(50000);
    } while (microtime(true) < $deadline);

    foreach (['inactive', 'missing'] as $role) {
        $login = authLogin('auth_' . $role);
        authCheck($login['status'] === 302 && $login['location'] === '../../login.php?error=Invalid username or password', $role . ' must receive the same generic rejection.');
        authCheck(authRequest('api/residents.php', ['cookie' => $login['initial_cookie']])['status'] === 401, $role . ' login must not grant access.');
    }
    authCheck((int) $db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn() === 0, 'Rejected logins must not register sessions.');
    authCheck(authLogin('auth_admin', 'wrong-password')['location'] === '../../login.php?error=Invalid username or password', 'Wrong passwords use the same generic rejection.');
    $admin = authLogin('auth_admin');
    authCheck($admin['status'] === 302 && $admin['location'] === '../../dashboard/admin.php', 'Active administrators can sign in.');
    authCheck($admin['cookie'] !== '' && $admin['cookie'] !== $admin['initial_cookie'], 'Login rotates the session ID.');
    foreach (['dashboard/admin.php', 'pages/users/index.php', 'api/residents.php', 'api/residents.php?export=csv', 'api/users.php'] as $route) {
        authCheck(authRequest($route, $admin)['status'] === 200, 'Administrators retain access to ' . $route);
    }
    foreach (['official' => 'admin.php', 'volunteer' => 'volunteer.php', 'resident' => 'resident.php'] as $role => $dashboard) {
        $identity = authLogin('auth_' . $role);
        authCheck($identity['status'] === 302 && $identity['location'] === '../../dashboard/' . $dashboard, $role . ' signs in to the correct dashboard.');
        authCheck(authRequest('dashboard/' . $dashboard, $identity)['status'] === 200, $role . ' can open its dashboard.');
        authCheck(authRequest('login.php', $identity)['location'] === 'dashboard/' . $dashboard, 'An already signed-in ' . $role . ' is sent to its own dashboard.');
        authCheck(authRequest('pages/profile/index.php', $identity)['status'] === 200, $role . ' can manage its own profile.');
        authCheck(authRequest('api/session.php', $identity)['status'] === 200, $role . ' retains session expiry/status support.');
        authCheck(authRequest('api/session.php', $identity, 'POST', [])['status'] === 200, $role . ' can explicitly renew its session.');
        authCheck(authRequest('api/presence.php', $identity, 'POST', [])['status'] === 200, $role . ' presence works.');
        authCheck(authRequest('api/users.php', $identity)['status'] === 403, $role . ' cannot read the account-management API.');
        authCheck(authRequest('api/bulk.php', $identity, 'POST', ['module' => 'users', 'action' => 'deactivate', 'ids' => [$ids['admin']]])['status'] === 403, $role . ' cannot run administrator bulk operations.');
        if ($role === 'official') {
            authCheck(authRequest('pages/residents/index.php', $identity)['status'] === 200 && authRequest('api/residents.php', $identity)['status'] === 200, 'Officials retain barangay record management.');
        } else {
            authCheck(authRequest('api/residents.php', $identity)['status'] === 403, $role . ' cannot read the resident registry.');
            authCheck(authRequest('dashboard/admin.php', $identity)['location'] === '/' . 'dashboard/' . $dashboard, $role . ' cannot open the management dashboard.');
        }
        if ($role === 'volunteer') {
            authCheck(authRequest('pages/distribution/index.php', $identity)['status'] === 200 && authRequest('api/distribution.php?paginate=1', $identity)['status'] === 200, 'Volunteers retain distribution tools.');
            authCheck(authRequest('api/resources.php?lookup=distribution', $identity)['status'] === 200, 'Volunteers can load compact distribution inventory choices.');
            authCheck(authRequest('api/resources.php?paginate=1', $identity)['status'] === 403, 'Volunteer inventory choices do not grant full inventory administration.');
        } elseif ($role === 'resident') {
            authCheck(authRequest('api/distribution.php', $identity)['status'] === 403 && authRequest('api/volunteers.php', $identity)['status'] === 403, 'Residents cannot read staff registers.');
        }
        $db->exec('UPDATE users SET force_password_change=1 WHERE id=' . $ids[$role]);
        authCheck(authRequest('api/session.php', $identity)['status'] === 200, 'Restricted ' . $role . ' sessions retain supporting session status.');
        authCheck(str_ends_with(authRequest('dashboard/' . $dashboard, $identity)['location'], '/pages/profile/change-password.php'), 'Temporary-password restrictions also apply to ' . $role . '.');
        authCheck(authRequest('pages/profile/change-password.php', $identity)['status'] === 200, $role . ' can open mandatory password replacement.');
        $forcedLogin = authLogin('auth_' . $role);
        authCheck($forcedLogin['location'] === '../../pages/profile/change-password.php', $role . ' temporary-password sign-in cannot bypass replacement.');
        $db->exec('UPDATE users SET force_password_change=0 WHERE id=' . $ids[$role]);
        $db->exec("UPDATE users SET status='Inactive' WHERE id=" . $ids[$role]);
        authCheck(authRequest('api/session.php', $identity)['status'] === 401, $role . ' deactivation revokes existing access.');
        $db->exec("UPDATE users SET status='Active' WHERE id=" . $ids[$role]);
        $expiredRole = authLogin('auth_' . $role);
        $expiredHash = hash('sha256', substr($expiredRole['cookie'], strpos($expiredRole['cookie'], '=') + 1));
        $db->exec("UPDATE user_sessions SET last_activity_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE session_hash='{$expiredHash}'");
        authCheck(authRequest('api/session.php', $expiredRole)['status'] === 401, $role . ' sessions still expire after inactivity.');
    }
    authCheck(authRequest('api/residents.php', ['cookie' => $admin['initial_cookie']])['status'] === 401, 'Pre-login cookies cannot access protected APIs.');
    authCheck(authRequest('api/residents.php', array_replace($admin, ['csrf' => 'invalid']), 'POST', ['first_name' => 'Blocked'])['status'] === 419, 'Existing CSRF protection remains enforced.');
    $before = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    foreach (['register.php', 'actions/auth/register.php'] as $route) {
        foreach (['GET', 'POST'] as $method) {
            $response = authRequest($route, [], $method, $method === 'POST' ? ['full_name' => 'Unwanted', 'username' => 'unwanted', 'password' => 'Private-test-password-42', 'confirm_password' => 'Private-test-password-42', 'role' => 'admin'] : null);
            authCheck($response['status'] === 403 && str_contains($response['body'], 'Public registration is disabled'), 'Disable direct registration: ' . $method . ' ' . $route);
        }
    }
    authCheck((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before, 'Public registration cannot insert users.');
    foreach (['official', 'volunteer', 'resident'] as $role) {
        authCheck(authRequest('api/residents.php?export=csv', authFixtureSession($ids[$role], $role))['status'] === ($role === 'official' ? 200 : 403), 'Resident registry exports enforce the ' . $role . ' permission.');
        authCheck(authRequest('api/users.php', authFixtureSession($ids[$role]))['status'] === 401, 'Session role alone cannot grant admin access.');
        authCheck(authRequest('pages/users/index.php', authFixtureSession($ids[$role], $role))['status'] === 302, 'Non-admin sessions cannot open protected pages.');
        authCheck(authRequest('api/users.php', authFixtureSession($ids[$role], $role), 'POST', ['full_name' => 'Blocked', 'role' => 'admin'])['status'] === 403, 'Non-admin sessions cannot create accounts.');
    }
    authCheck(authRequest('api/users.php', authFixtureSession($ids['inactive']))['status'] === 401, 'Inactive admins cannot reuse sessions.');
    authCheck(authRequest('api/users.php', authFixtureSession($ids['admin'], 'admin', false))['status'] === 401, 'Unregistered sessions must require a new login.');
    authCheck(authRequest('api/presence.php', authFixtureSession($ids['admin'], 'admin', true, true), 'POST', [])['status'] === 401, 'Presence cannot revive a revoked session.');
    $target = authLogin('auth_admin');
    $db->exec("UPDATE users SET status = 'Inactive' WHERE id = " . $ids['admin']);
    authCheck(authRequest('api/residents.php', $target)['status'] === 401, 'Signed-in admins lose access on deactivation.');
    $db->exec("UPDATE users SET status = 'Active' WHERE id = " . $ids['admin']);
    authCheck(authRequest('api/residents.php', $target)['status'] === 401, 'Invalidated cookies stay unusable after reactivation.');
    $target = authLogin('auth_admin');
    $db->exec("UPDATE users SET role = 'official' WHERE id = " . $ids['admin']);
    authCheck(authRequest('pages/users/index.php', $target)['status'] === 302, 'Demoted admins immediately lose page access.');
    $db->exec("UPDATE users SET role = 'admin' WHERE id = " . $ids['admin']);
    $operator = authLogin('auth_admin');
    $insert->execute(['Second administrator', 'auth_second', 'second@example.test', $hash, 'admin', 'Active']); $secondId = (int) $db->lastInsertId();
    $second = authLogin('auth_second');
    $update = ['id' => $secondId, 'full_name' => 'Second administrator', 'email' => 'second@example.test', 'role' => 'official', 'status' => 'Active'];
    authCheck(authRequest('api/users.php', $operator, 'PUT', $update)['status'] === 200, 'Admin role updates work.');
    $update['role'] = 'admin';
    authCheck(authRequest('api/users.php', $operator, 'PUT', $update)['status'] === 200, 'Admin promotion works.');
    authCheck(authRequest('api/residents.php', $second)['status'] === 401, 'Promotion cannot revive a session revoked by a role change.');
    $second = authLogin('auth_second'); $update['status'] = 'Inactive';
    authCheck(authRequest('api/users.php', $operator, 'PUT', $update)['status'] === 200, 'Admin deactivation works.');
    $update['status'] = 'Active';
    authCheck(authRequest('api/users.php', $operator, 'PUT', $update)['status'] === 200, 'Admin reactivation works.');
    authCheck(authRequest('api/residents.php', $second)['status'] === 401, 'Reactivation cannot revive a revoked session.');
    $second = authLogin('auth_second');
    $logout = authRequest('actions/auth/logout.php', $second, 'POST', ['csrf_token' => $second['csrf']]);
    authCheck($logout['status'] === 302 && authRequest('api/residents.php', $second)['status'] === 401, 'Logout revokes protected access.');
    authCheck(authRequest('api/users.php', $operator, 'DELETE', ['id' => $secondId])['status'] === 200, 'Disable account before testing the Enable action.');
    $disabledAccount = $db->query('SELECT * FROM users WHERE id = ' . $secondId)->fetch();
    authCheck(authRequest('api/users.php', array_replace($operator, ['csrf' => 'invalid']), 'PUT', ['id' => $secondId, 'action' => 'activate'])['status'] === 419, 'Enable requires a valid CSRF token.');
    authCheck(authRequest('api/users.php', [], 'PUT', ['id' => $secondId, 'action' => 'activate'])['status'] === 401, 'Anonymous requests cannot enable accounts.');
    authCheck($db->query('SELECT status FROM users WHERE id = ' . $secondId)->fetchColumn() === 'Inactive', 'Rejected enable requests leave the account disabled.');
    authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $secondId, 'action' => 'activate', 'role' => 'resident', 'password' => 'Unwanted-password-42'])['status'] === 200, 'Enable restores an inactive account.');
    $enabledAccount = $db->query('SELECT * FROM users WHERE id = ' . $secondId)->fetch();
    authCheck($enabledAccount['status'] === 'Active', 'Enable persists Active status in the database.');
    foreach (['role', 'password_hash', 'full_name', 'username', 'email'] as $field) authCheck($enabledAccount[$field] === $disabledAccount[$field], 'Enable preserves ' . $field);
    $activationLogs = (int) $db->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'activate' AND entity_type = 'user' AND entity_id = " . $secondId)->fetchColumn();
    authCheck($activationLogs === 1, 'Enable is audited.');
    authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $secondId, 'action' => 'activate'])['status'] === 200, 'Repeated enable requests are harmless.');
    authCheck((int) $db->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'activate' AND entity_type = 'user' AND entity_id = " . $secondId)->fetchColumn() === $activationLogs, 'Repeated enable does not duplicate activation logs.');
    authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => 999999, 'action' => 'activate'])['status'] === 404, 'Enable rejects nonexistent accounts.');
    if (getenv('SAGIPBRO_AUTH_BROWSER') === '1') authStatusBrowser($operator, $secondId, $environment);
    $passwordAccount = ['full_name' => 'Password policy test', 'username' => 'policy_test', 'email' => 'policy@example.test', 'role' => 'admin'];
    $usersBeforePolicy = $db->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $weakSetup = authSetupAdmin('policy_cli_short', 'abc', $environment);
    authCheck($weakSetup['exit'] !== 0 && str_contains($weakSetup['output'], 'at least 8'), 'CLI setup rejects passwords below the eight-character minimum.');
    $commonSetup = authSetupAdmin('policy_cli_common', 'Password123456789!', $environment);
    authCheck($commonSetup['exit'] !== 0 && str_contains($commonSetup['output'], 'less common'), 'CLI setup rejects common passwords.');
    $unavailableEnvironment = $environment;
    $unavailableEnvironment['https_proxy'] = 'http://127.0.0.1:1';
    $unavailableEnvironment['HTTPS_PROXY'] = 'http://127.0.0.1:1';
    $unavailableEnvironment['no_proxy'] = ''; $unavailableEnvironment['NO_PROXY'] = '';
    $unavailableSetup = authSetupAdmin('policy_cli_unavailable', 'Unavailable-test-' . bin2hex(random_bytes(12)), $unavailableEnvironment);
    authCheck($unavailableSetup['exit'] !== 0 && str_contains($unavailableSetup['output'], 'Password safety check is unavailable'), 'CLI setup blocks password writes when screening cannot run.');
    foreach (['short', 'Password123456789!', str_repeat('é', 7), str_repeat('a', 73), []] as $unsafe) {
        authCheck(authRequest('api/users.php', $operator, 'POST', $passwordAccount + ['password' => $unsafe])['status'] === 422, 'Account creation enforces the server password policy.');
        authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $secondId, 'action' => 'reset_password', 'password' => $unsafe])['status'] === 422, 'Password resets enforce the same policy.');
    }
    $profileIdentity = authLogin('auth_second');
    foreach (['short', 'Password123456789!', str_repeat('a', 73)] as $unsafe) {
        $rejectedChange = authRequest('pages/profile/index.php', $profileIdentity, 'POST', ['intent' => 'password', 'csrf_token' => $profileIdentity['csrf'], 'current_password' => 'Private-test-password-42', 'new_password' => $unsafe, 'confirm_new_password' => $unsafe]);
        authCheck($rejectedChange['status'] === 302 && str_contains($rejectedChange['location'], 'error='), 'Profile changes reject unsafe passwords.');
    }
    // last_login_at changes during the sign-in above; compare password hashes and user count.
    $afterPolicy = $db->query('SELECT * FROM users ORDER BY id')->fetchAll();
    authCheck(count($afterPolicy) === count($usersBeforePolicy), 'Rejected passwords do not create accounts.');
    authCheck(array_column($afterPolicy, 'password_hash', 'id') === array_column($usersBeforePolicy, 'password_hash', 'id'), 'Rejected passwords never replace stored hashes.');
    $profileHtml = authRequest('pages/profile/index.php', $profileIdentity)['body'];
    authCheck(str_contains($profileHtml, 'minlength="8"') && !str_contains($profileHtml, 'minlength="15"'), 'Profile forms display the eight-character minimum.');
    $usersHtml = authRequest('pages/users/index.php', $operator)['body'];
    authCheck(substr_count($usersHtml, 'minlength="8"') === 3 && !str_contains($usersHtml, 'minlength="15"'), 'Creation and reset forms display the eight-character minimum.');
    if (getenv('SAGIPBRO_PASSWORD_LIVE_TEST') === '1') {
        $cliPassword = 'CLI-only passphrase ' . bin2hex(random_bytes(16));
        $cliAccount = authSetupAdmin('policy_cli_valid', $cliPassword, $environment);
        authCheck($cliAccount['exit'] === 0 && str_contains($cliAccount['output'], 'created successfully'), 'CLI setup accepts a screened safe password.');
        $cliHash = $db->query("SELECT password_hash FROM users WHERE username = 'policy_cli_valid'")->fetchColumn();
        authCheck(is_string($cliHash) && password_verify($cliPassword, $cliHash), 'CLI setup stores a verifiable hash.');
        $livePassword = 'Test-only passphrase ' . bin2hex(random_bytes(16));
        $createdAccount = authRequest('api/users.php', $operator, 'POST', $passwordAccount + ['password' => $livePassword]);
        authCheck($createdAccount['status'] === 201, 'Creation accepts a safe password after real breach screening.');
        $policyId = (int) json_decode($createdAccount['body'], true)['id'];
        $stored = $db->query('SELECT password_hash FROM users WHERE id = ' . $policyId)->fetchColumn();
        authCheck(password_verify($livePassword, $stored), 'Creation stores a verifiable password hash.');
        $beforeBreach = $db->query('SELECT password_hash FROM users WHERE id = ' . $policyId)->fetchColumn();
        $oldSessionOne = authLogin('policy_test', $livePassword);
        $oldSessionTwo = authLogin('policy_test', $livePassword);
        authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $policyId, 'action' => 'reset_password', 'password' => 'correct horse battery staple'])['status'] === 422, 'Real breach screening blocks a known exposed reset password.');
        authCheck($db->query('SELECT password_hash FROM users WHERE id = ' . $policyId)->fetchColumn() === $beforeBreach, 'A breached password does not overwrite the current hash.');
        $resetPassword = 'Reset-only passphrase ' . bin2hex(random_bytes(16));
        $sessionsBeforeFailedReset = $db->query('SELECT * FROM user_sessions WHERE user_id=' . $policyId . ' ORDER BY session_hash')->fetchAll();
        $db->exec("CREATE TRIGGER fail_password_audit BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_PASSWORD_AUDIT_FAILURE'");
        authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $policyId, 'action' => 'reset_password', 'password' => $resetPassword])['status'] === 503, 'Audit failure rejects the reset safely.');
        authCheck($db->query('SELECT password_hash FROM users WHERE id=' . $policyId)->fetchColumn() === $beforeBreach, 'A failed reset rolls back the password.');
        authCheck($db->query('SELECT * FROM user_sessions WHERE user_id=' . $policyId . ' ORDER BY session_hash')->fetchAll() === $sessionsBeforeFailedReset, 'A failed reset rolls back session revocations.');
        $db->exec('DROP TRIGGER fail_password_audit');
        authCheck(authRequest('api/users.php', $operator, 'PUT', ['id' => $policyId, 'action' => 'reset_password', 'password' => $resetPassword])['status'] === 200, 'Reset accepts a screened safe password.');
        authCheck(authRequest('api/users.php', $oldSessionOne)['status'] === 401 && authRequest('api/users.php', $oldSessionTwo)['status'] === 401, 'Reset revokes every existing session.');
        $otherTemporarySession = authLogin('policy_test', $resetPassword);
        $policyIdentity = authLogin('policy_test', $resetPassword);
        authCheck($policyIdentity['location'] === '../../pages/profile/change-password.php', 'Temporary passwords lead only to password replacement.');
        $changedPassword = 'Change-only passphrase ' . bin2hex(random_bytes(16));
        $sessionsBeforeFailedChange = $db->query('SELECT session_hash,signed_out_at FROM user_sessions WHERE user_id=' . $policyId . ' ORDER BY session_hash')->fetchAll();
        $resetHash = $db->query('SELECT password_hash FROM users WHERE id=' . $policyId)->fetchColumn();
        $db->exec("CREATE TRIGGER fail_change_audit BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_CHANGE_AUDIT_FAILURE'");
        $failedChange = authRequest('pages/profile/index.php', $policyIdentity, 'POST', ['intent' => 'password', 'csrf_token' => $policyIdentity['csrf'], 'current_password' => $resetPassword, 'new_password' => $changedPassword, 'confirm_new_password' => $changedPassword]);
        authCheck($failedChange['status'] === 302 && str_contains($failedChange['location'], 'error='), 'A failed password-change audit returns a safe error.');
        authCheck($db->query('SELECT password_hash FROM users WHERE id=' . $policyId)->fetchColumn() === $resetHash && (int) $db->query('SELECT force_password_change FROM users WHERE id=' . $policyId)->fetchColumn() === 1, 'Failed changes roll back the password and restriction flag.');
        authCheck($db->query('SELECT session_hash,signed_out_at FROM user_sessions WHERE user_id=' . $policyId . ' ORDER BY session_hash')->fetchAll() === $sessionsBeforeFailedChange, 'Failed changes roll back session revocations.');
        $db->exec('DROP TRIGGER fail_change_audit');
        $changed = authRequest('pages/profile/index.php', $policyIdentity, 'POST', ['intent' => 'password', 'csrf_token' => $policyIdentity['csrf'], 'current_password' => $resetPassword, 'new_password' => $changedPassword, 'confirm_new_password' => $changedPassword]);
        authCheck($changed['status'] === 302 && str_contains($changed['location'], 'login.php?success='), 'Profile accepts a screened safe password and requires a new login.');
        $stored = $db->query('SELECT password_hash FROM users WHERE id = ' . $policyId)->fetchColumn();
        authCheck(password_verify($changedPassword, $stored) && !password_verify($resetPassword, $stored), 'Profile changes replace the hash with the new credential.');
        authCheck(authRequest('api/users.php', $policyIdentity)['status'] === 401, 'The changing session is signed out.');
        authCheck(authRequest('api/users.php', $otherTemporarySession)['status'] === 401, 'A password change also revokes other sessions.');
        authCheck((int) $db->query('SELECT force_password_change FROM users WHERE id=' . $policyId)->fetchColumn() === 0, 'Successful replacement clears the temporary password flag.');
        authCheck(authLogin('policy_test', $changedPassword)['location'] === '../../dashboard/admin.php', 'The replacement password restores normal administrator access.');
    }
    $timed = authLogin('auth_admin');
    $timedHash = hash('sha256', substr($timed['cookie'], strpos($timed['cookie'], '=') + 1));
    $db->exec("UPDATE user_sessions SET last_activity_at=DATE_SUB(NOW(),INTERVAL 19 MINUTE) WHERE session_hash='{$timedHash}'");
    $beforePolling = $db->query("SELECT last_activity_at,created_at FROM user_sessions WHERE session_hash='{$timedHash}'")->fetch();
    authCheck(authRequest('api/session.php', $timed)['status'] === 200, 'Near-expiry sessions can inspect their remaining time.');
    authCheck(authRequest('api/presence.php', $timed, 'POST', [])['status'] === 200, 'Background presence still works near expiry.');
    authCheck(authRequest('api/users.php', $timed)['status'] === 200, 'Background register polling still works near expiry.');
    authCheck($db->query("SELECT last_activity_at,created_at FROM user_sessions WHERE session_hash='{$timedHash}'")->fetch() === $beforePolling, 'Background status, presence, and register polling do not renew session activity.');
    authCheck(authRequest('api/session.php', array_replace($timed, ['csrf' => 'invalid']), 'POST', [])['status'] === 419, 'Stay signed in requires CSRF protection.');
    $extended = authRequest('api/session.php', $timed, 'POST', []);
    $timing = json_decode($extended['body'], true);
    authCheck($extended['status'] === 200 && $timing['idle_expires_at'] - $timing['server_now'] >= 1198, 'Explicit user activity renews the twenty-minute idle window.');
    authCheck($db->query("SELECT created_at FROM user_sessions WHERE session_hash='{$timedHash}'")->fetchColumn() === $beforePolling['created_at'], 'Stay signed in cannot move the absolute sign-in time.');
    $db->exec("UPDATE user_sessions SET last_activity_at=DATE_SUB(NOW(),INTERVAL 19 MINUTE) WHERE session_hash='{$timedHash}'");
    authCheck(authRequest('pages/users/index.php', $timed)['status'] === 200, 'Foreground navigation is allowed before expiry.');
    authCheck((int) $db->query("SELECT UNIX_TIMESTAMP()-UNIX_TIMESTAMP(last_activity_at) FROM user_sessions WHERE session_hash='{$timedHash}'")->fetchColumn() < 5, 'Foreground navigation records user activity.');
    $db->exec("UPDATE user_sessions SET last_activity_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE session_hash='{$timedHash}'");
    authCheck(authRequest('api/users.php', $timed)['status'] === 401, 'Idle expiry rejects protected APIs at twenty minutes.');
    authCheck($db->query("SELECT signed_out_at FROM user_sessions WHERE session_hash='{$timedHash}'")->fetchColumn() !== null, 'Idle expiry revokes the database session.');
    authCheck(authRequest('api/session.php', $timed, 'POST', [])['status'] === 401, 'Stay signed in cannot revive an expired session.');
    authCheck(authRequest('pages/users/index.php', $timed)['status'] === 302, 'Expired sessions cannot open protected pages.');
    $absolute = authLogin('auth_admin');
    $absoluteHash = hash('sha256', substr($absolute['cookie'], strpos($absolute['cookie'], '=') + 1));
    $db->exec("UPDATE user_sessions SET created_at=DATE_SUB(NOW(),INTERVAL 8 HOUR),last_activity_at=NOW() WHERE session_hash='{$absoluteHash}'");
    authCheck(authRequest('api/session.php', $absolute, 'POST', [])['status'] === 401, 'The eight-hour cap applies even to active sessions.');
    $beforeRehash = $db->query('SELECT password_hash FROM users WHERE id=' . $secondId)->fetchColumn();
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash('Private-test-password-42', PASSWORD_BCRYPT, ['cost' => 4]), $secondId]);
    $rehashSession = authLogin('auth_second');
    authCheck(authRequest('api/users.php', $rehashSession)['status'] === 200, 'Login rehashing records the new credential fingerprint rather than invalidating its own session.');
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$beforeRehash, $secondId]);
    $required = authLogin('auth_second');
    $unchangedHash = $db->query('SELECT password_hash FROM users WHERE id=' . $secondId)->fetchColumn();
    $db->exec('UPDATE users SET force_password_change=1 WHERE id=' . $secondId);
    $restricted = authRequest('api/users.php', $required);
    authCheck($restricted['status'] === 403 && (json_decode($restricted['body'], true)['code'] ?? '') === 'password_change_required', 'The mandatory-password flag is checked from the database for existing sessions.');
    foreach (['dashboard/admin.php', 'pages/users/index.php', 'pages/profile/index.php'] as $route) {
        authCheck(str_ends_with(authRequest($route, $required)['location'], '/pages/profile/change-password.php'), 'Temporary-password sessions cannot open ' . $route);
    }
    authCheck(authRequest('pages/profile/change-password.php', $required)['status'] === 200, 'Temporary-password sessions can open the restricted replacement form.');
    authCheck(str_contains(authRequest('pages/profile/index.php', $required, 'POST', ['intent' => 'profile', 'csrf_token' => $required['csrf']])['location'], '/pages/profile/change-password.php'), 'Temporary sessions cannot submit unrelated profile changes.');
    $reuse = authRequest('pages/profile/index.php', $required, 'POST', ['intent' => 'password', 'csrf_token' => $required['csrf'], 'current_password' => 'Private-test-password-42', 'new_password' => 'Private-test-password-42', 'confirm_new_password' => 'Private-test-password-42']);
    authCheck(str_contains($reuse['location'], 'error=') && str_contains(urldecode($reuse['location']), 'different'), 'Replacing a temporary password with itself is rejected.');
    authCheck($db->query('SELECT password_hash FROM users WHERE id=' . $secondId)->fetchColumn() === $unchangedHash && (int) $db->query('SELECT force_password_change FROM users WHERE id=' . $secondId)->fetchColumn() === 1, 'Rejected replacements preserve the hash and restriction.');
    authCheck(authRequest('actions/auth/logout.php', $required, 'POST', ['csrf_token' => $required['csrf']])['status'] === 302, 'Restricted sessions can still log out.');
    $db->exec('UPDATE users SET force_password_change=0 WHERE id=' . $secondId);
    $stale = authLogin('auth_second');
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash('Changed-by-another-session-42', PASSWORD_DEFAULT), $secondId]);
    authCheck(authRequest('api/users.php', $stale)['status'] === 401, 'A changed credential also invalidates a stale session fingerprint.');
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$unchangedHash, $secondId]);
    // Every authLogin obtains a fresh browser session, so these exercise the old bypass.
    $db->exec('DELETE FROM login_rate_limits');
    $invalidLocation = '../../login.php?error=Invalid username or password';
    $cooldownLocation = '../../login.php?error=Unable to sign in. Please try again later';
    foreach (['auth_second', 'AUTH_SECOND', ' auth_second ', 'auth_second', 'AUTH_SECOND'] as $username) {
        authCheck(authLogin($username, 'wrong-password')['location'] === $invalidLocation, 'Failed passwords count across fresh cookies and database-equivalent usernames.');
    }
    $accountKey = hash('sha256', 'user:' . $secondId);
    $blockedUntil = (int) $db->query("SELECT blocked_until FROM login_rate_limits WHERE bucket_scope='account' AND bucket_key='{$accountKey}'")->fetchColumn();
    authCheck($blockedUntil > time(), 'The fifth failure starts an account cooldown.');
    authCheck(authLogin('auth_second')['location'] === $cooldownLocation, 'Fresh cookies and even valid credentials cannot bypass an account cooldown.');
    authCheck((int) $db->query("SELECT blocked_until FROM login_rate_limits WHERE bucket_scope='account' AND bucket_key='{$accountKey}'")->fetchColumn() === $blockedUntil, 'Blocked HTTP retries do not extend the cooldown.');
    authCheck(authLogin('auth_admin')['location'] === '../../dashboard/admin.php', 'Another account can sign in below the IP limit.');
    $db->exec("UPDATE login_rate_limits SET blocked_until=UNIX_TIMESTAMP()-1 WHERE bucket_scope='account' AND bucket_key='{$accountKey}'");
    authCheck(authLogin('auth_second')['location'] === '../../dashboard/admin.php', 'Account access automatically recovers after cooldown expiry.');
    authCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='account' AND bucket_key='{$accountKey}'")->fetchColumn() === 0, 'Successful login clears that account failure counter.');
    $db->exec('DELETE FROM login_rate_limits');
    for ($i = 0; $i < 5; ++$i) authCheck(authLogin('never_registered', 'wrong-password')['location'] === $invalidLocation, 'Unknown accounts use the same failure policy.');
    authCheck(authLogin('never_registered', 'wrong-password')['location'] === $cooldownLocation, 'Unknown accounts receive the same generic cooldown response.');
    $db->exec('DELETE FROM login_rate_limits');
    for ($i = 0; $i < 30; ++$i) authCheck(authLogin('unknown_ip_test_' . $i, 'wrong-password')['location'] === $invalidLocation, 'Distinct usernames still count toward the IP limit.');
    authCheck(authLogin('auth_admin', 'Private-test-password-42', ['X-Forwarded-For: 203.0.113.99', 'X-Real-IP: 203.0.113.99'])['location'] === $cooldownLocation, 'Spoofed forwarding headers cannot bypass the IP cooldown.');
    $db->exec("UPDATE login_rate_limits SET blocked_until=UNIX_TIMESTAMP()-1 WHERE bucket_scope='ip'");
    authCheck(authLogin('auth_admin')['location'] === '../../dashboard/admin.php', 'The IP cooldown expires automatically.');
    $db->exec('RENAME TABLE login_rate_limits TO unavailable_limits');
    authCheck(authLogin('auth_admin')['status'] === 503, 'Unavailable throttle storage rejects login instead of bypassing protection.');
    $db->exec('RENAME TABLE unavailable_limits TO login_rate_limits');
    authCheck(authLogin('auth_admin')['location'] === '../../dashboard/admin.php', 'Login recovers when throttle storage is restored.');
    authCheck(authRequest('resources.php')['status'] === 200, 'Anonymous public information stays accessible.');
    $db->exec('RENAME TABLE user_sessions TO unavailable_sessions');
    authCheck(authRequest('api/users.php', $operator)['status'] === 503, 'Validation outages must deny access.');
    $db->exec('RENAME TABLE unavailable_sessions TO user_sessions');
    authCheck(authRequest('api/users.php', $operator)['status'] === 200, 'Transient outages do not destroy valid sessions.');
    authCheck(!preg_match('/PHP (?:Warning|Fatal error)|Uncaught (?:Error|Exception)/', (string) file_get_contents($directory . '/server.log')), 'Requests emit no PHP warnings or fatal errors.');
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . ($error instanceof LogicException ? $error->getMessage() : get_class($error) . ' at line ' . $error->getLine()) . "\n");
} finally {
    if (is_resource($server)) { if (proc_get_status($server)['running']) proc_terminate($server); proc_close($server); }
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_auth_test_[a-f0-9]{20}\z/', $name)) $db->exec('DROP DATABASE `' . $name . '`');
    // Remove only this run's private directory after checking its absolute path.
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporaryRoot !== false && str_starts_with(strtolower($resolved), strtolower($temporaryRoot . DIRECTORY_SEPARATOR . 'sagipbro-auth-')) && preg_match('/\Asagipbro-auth-[a-f0-9]{16}\z/', basename($resolved))) {
        $remove = static function (string $target) use (&$remove, $resolved): void {
            $absolute = realpath($target);
            if ($absolute === false || (strcasecmp($absolute, $resolved) !== 0 && !str_starts_with(strtolower($absolute), strtolower($resolved . DIRECTORY_SEPARATOR)))) return;
            if (is_dir($target) && !is_link($target)) {
                foreach (scandir($target) as $entry) if ($entry !== '.' && $entry !== '..') $remove($target . DIRECTORY_SEPARATOR . $entry);
                rmdir($target);
            } else unlink($target);
        };
        $remove($resolved);
    }
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED' : 'PASS') . ': ' . $checks . " administrator authentication HTTP assertions; application data untouched.\n");
exit($failed ? 1 : 0);
