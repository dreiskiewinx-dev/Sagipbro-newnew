<?php
declare(strict_types=1);

/**
 * Run: php tests/resident_account_http.php
 * Requires PDO MySQL and proc_open. Uses a new, isolated database and sessions.
 * Set SAGIPBRO_RESIDENT_ACCOUNT_BROWSER=1 for responsive layout and password-toggle checks.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this test from the command line.');
}
require_once __DIR__ . '/../config/config.php';
$project = dirname(__DIR__);
$testDatabase = 'sagipbro_account_test_' . bin2hex(random_bytes(10));
$database = null;
$createdDatabase = false;
$server = null;
$directory = null;
$checks = 0;
$failed = false;
$address = '';

function accountCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function accountRequest(string $path, string $cookie = '', ?array $data = null): array
{
    global $address;
    $headers = ['Connection: close'];
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if ($data !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $options = ['method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    if ($data !== null) $options['content'] = http_build_query($data);
    $stream = @fopen($address . '/' . ltrim($path, '/'), 'rb', false, stream_context_create(['http' => $options]));
    if ($stream === false) throw new LogicException('The isolated HTTP server did not respond.');
    $body = (string) stream_get_contents($stream);
    $meta = stream_get_meta_data($stream);
    fclose($stream);
    $responseHeaders = implode("\n", $meta['wrapper_data'] ?? []);
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $responseHeaders, $status);
    preg_match('/^Location:\s*(.*)$/mi', $responseHeaders, $location);
    return ['status' => (int) ($status[1] ?? 0), 'body' => $body, 'location' => trim($location[1] ?? '')];
}

function accountIdentity(int $id, string $role, string $name): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_id('');
        session_start();
    }
    $_SESSION = ['user_id' => $id, 'role' => $role, 'full_name' => $name, 'username' => $role . '_account_test'];
    $identity = ['cookie' => session_name() . '=' . session_id(), 'csrf_token' => csrfToken()];
    session_write_close();
    return $identity;
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    $user = getenv('SAGIPBRO_DB_USER');
    $password = getenv('SAGIPBRO_DB_PASSWORD');
    accountCheck(strpbrk($host, ";\r\n") === false && ctype_digit($port), 'Invalid database host or port.');
    $database = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user === false ? 'root' : $user, $password === false ? '' : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $database->exec("SET time_zone = '+08:00'");
    $database->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $createdDatabase = true;
    $database->exec('USE `' . $testDatabase . '`');
    foreach (explode(';', (string) file_get_contents($project . '/database/sagipbro.sql')) as $statement) {
        $statement = trim($statement);
        if ($statement !== '' && !preg_match('/\A(?:CREATE\s+DATABASE\b|USE\b)/i', $statement)) $database->exec($statement);
    }
    $insert = $database->prepare('INSERT INTO users (full_name, username, email, password_hash, role, position, language) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $originalPassword = 'Original-account-password-42';
    $originalHash = password_hash($originalPassword, PASSWORD_DEFAULT);
    $identities = [];
    foreach (['resident', 'admin', 'volunteer', 'official'] as $role) {
        $insert->execute([ucfirst($role) . ' Account Test', $role . '_account_test', $role . '@example.test', $originalHash, $role, 'Original position', 'Filipino']);
        $identities[$role] = (int) $database->lastInsertId();
    }
    $residentId = $identities['resident'];
    $notice = $database->prepare("INSERT INTO announcements (title, body, status, priority, published_at, created_by) VALUES (?, ?, 'Published', 'Normal', NOW(), ?)");
    $notice->execute(['Public-only popup fixture', 'Published notice used to check dashboard visibility.', $identities['admin']]);
    $otherAccounts = $database->query('SELECT * FROM users WHERE id <> ' . $residentId . ' ORDER BY id')->fetchAll();
    $database->exec("INSERT INTO residents (first_name, last_name, sex, address) VALUES ('Resident', 'Account Test', 'Other', 'Official test address')");
    $officialRecords = $database->query('SELECT * FROM residents')->fetchAll();

    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-account-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new LogicException('Could not create private test directory.');
    putenv('SAGIPBRO_SESSION_PATH=' . $directory);
    require_once $project . '/config/session.php';
    $resident = accountIdentity($residentId, 'resident', 'Resident Account Test');
    $staff = [];
    foreach (['admin', 'official', 'volunteer'] as $role) $staff[$role] = accountIdentity($identities[$role], $role, ucfirst($role) . ' Account Test');
    $environment = getenv();
    $environment['SAGIPBRO_DB_NAME'] = $testDatabase;
    $environment['SAGIPBRO_SESSION_PATH'] = $directory;
    $environment['SAGIPBRO_BASE_URL'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
    if ($reservation === false) throw new LogicException('Could not reserve test port.');
    $listen = stream_socket_get_name($reservation, false);
    fclose($reservation);
    $address = 'http://' . $listen;
    $router = '<?php $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if ($path === "/__account_browser") { setcookie(' . var_export(session_name(), true) . ', ' . var_export(substr($resident['cookie'], strpos($resident['cookie'], '=') + 1), true) . ', ["path" => "/", "httponly" => true, "samesite" => "Lax"]); readfile(' . var_export($directory . '/browser.html', true) . '); return true; } if (in_array($path, ["/pages/profile/index.php", "/dashboard/resident.php"], true)) { $target = ' . var_export($project, true) . ' . $path; chdir(dirname($target)); ob_start(); require $target; header_remove("X-Frame-Options"); ob_end_flush(); return true; } return false;';
    file_put_contents($directory . '/router.php', $router);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', $listen, '-t', $project, $directory . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'ab'], 2 => ['file', $directory . '/server.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
    if (!is_resource($server)) throw new LogicException('Could not start isolated server.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 5;
    do {
        $socket = @stream_socket_client('tcp://' . $listen, $errorNumber, $errorString, 0.1);
        if (is_resource($socket)) { fclose($socket); break; }
        usleep(50000);
    } while (microtime(true) < $deadline);

    $route = 'pages/profile/index.php';
    $guest = accountRequest($route);
    accountCheck($guest['status'] === 302 && str_contains($guest['location'], '/login.php'), 'Guests must sign in to manage an account.');
    $page = accountRequest($route, $resident['cookie']);
    accountCheck($page['status'] === 200 && str_contains($page['body'], 'id="accountFullName"') && str_contains($page['body'], 'name="current_password"'), 'Residents can open their personal information and password forms.');
    accountCheck(!str_contains($page['body'], 'data-floating-announcement') && !str_contains($page['body'], 'floating-announcement.css'), 'The resident account page does not load the public announcement popup.');
    accountCheck(!str_contains($page['body'], 'admin-sidebar') && !str_contains($page['body'], 'Notification preferences') && !str_contains($page['body'], 'View all logs'), 'Residents see their portal layout and account controls.');
    $portal = accountRequest('dashboard/resident.php', $resident['cookie']);
    accountCheck(str_contains($portal['body'], 'id="resident-account-title"') && str_contains($portal['body'], 'href="../pages/profile/index.php"'), 'The resident portal has a visible Update account link.');
    accountCheck(!str_contains($portal['body'], 'data-floating-announcement') && !str_contains($portal['body'], 'floating-announcement.css'), 'The resident dashboard does not load the public announcement popup.');
    foreach ($staff as $role => $identity) {
        $staffPage = accountRequest($route, $identity['cookie']);
        accountCheck($staffPage['status'] === 200 && str_contains($staffPage['body'], 'id="profilePosition"') && str_contains($staffPage['body'], 'id="notificationsHeading"'), $role . ' retains the existing profile page.');
        accountCheck(!str_contains($staffPage['body'], 'data-floating-announcement') && !str_contains($staffPage['body'], 'floating-announcement.css'), $role . ' account pages do not load the public announcement popup.');
    }
    $form = ['intent' => 'profile', 'csrf_token' => $resident['csrf_token'], 'full_name' => 'Updated Resident', 'email' => 'updated@example.test', 'contact' => '+639171234567'];
    $before = $database->query('SELECT * FROM users ORDER BY id')->fetchAll();
    $csrfFailure = accountRequest($route, $resident['cookie'], array_replace($form, ['csrf_token' => 'invalid']));
    accountCheck($csrfFailure['status'] === 419 && $database->query('SELECT * FROM users ORDER BY id')->fetchAll() === $before, 'Invalid CSRF tokens cannot change account details.');
    foreach ([['full_name' => ' '], ['email' => 'not-an-email'], ['email' => str_repeat('a', 150) . '@example.test'], ['contact' => 'not-a-phone'], ['contact' => str_repeat('1', 31)]] as $invalid) {
        $response = accountRequest($route, $resident['cookie'], array_replace($form, $invalid));
        accountCheck($response['status'] === 302 && str_contains($response['location'], 'error=') && $database->query('SELECT * FROM users ORDER BY id')->fetchAll() === $before, 'Invalid personal information must be rejected without mutation.');
    }
    $response = accountRequest($route, $resident['cookie'], $form + ['id' => $identities['admin'], 'role' => 'admin', 'username' => 'takeover', 'position' => 'Changed position', 'language' => 'English']);
    $saved = $database->query('SELECT * FROM users WHERE id = ' . $residentId)->fetch();
    accountCheck($response['status'] === 302 && str_contains($response['location'], 'success=') && $saved['full_name'] === $form['full_name'] && $saved['email'] === $form['email'] && $saved['contact'] === $form['contact'], 'Residents can save their name, email and international phone number.');
    accountCheck($saved['role'] === 'resident' && $saved['username'] === 'resident_account_test' && $saved['position'] === 'Original position' && $saved['language'] === 'Filipino', 'Resident requests cannot change their role, username or staff fields.');
    accountCheck($database->query('SELECT * FROM users WHERE id <> ' . $residentId . ' ORDER BY id')->fetchAll() === $otherAccounts, 'Residents cannot update another account by submitting its ID.');
    accountCheck($database->query('SELECT * FROM residents')->fetchAll() === $officialRecords, 'Account changes do not alter official resident records.');
    $portal = accountRequest('dashboard/resident.php', $resident['cookie']);
    accountCheck(str_contains($portal['body'], 'Welcome, Updated Resident.'), 'The updated account name is saved in the session.');
    $escapedName = 'Resident <script>alert(1)</script>';
    accountRequest($route, $resident['cookie'], array_replace($form, ['full_name' => $escapedName, 'contact' => '']));
    $page = accountRequest($route, $resident['cookie']);
    accountCheck(str_contains($page['body'], htmlspecialchars($escapedName, ENT_QUOTES, 'UTF-8')) && !str_contains($page['body'], $escapedName), 'Saved names are safely escaped in account forms.');
    accountCheck($database->query('SELECT contact FROM users WHERE id = ' . $residentId)->fetchColumn() === null, 'The optional contact number can be cleared.');
    accountRequest($route, $resident['cookie'], $form);
    $response = accountRequest($route, $resident['cookie'], ['csrf_token' => $resident['csrf_token'], 'intent' => 'notifications', 'notify_stock' => '0']);
    accountCheck(str_contains($response['location'], 'error='), 'Residents cannot submit staff notification preferences.');

    $passwordForm = ['intent' => 'password', 'csrf_token' => $resident['csrf_token'], 'current_password' => $originalPassword, 'new_password' => 'New-account-password-84', 'confirm_new_password' => 'New-account-password-84'];
    foreach ([['csrf_token' => 'invalid'], ['current_password' => 'wrong-password'], ['new_password' => 'short', 'confirm_new_password' => 'short'], ['confirm_new_password' => 'mismatched-password']] as $invalid) {
        $response = accountRequest($route, $resident['cookie'], array_replace($passwordForm, $invalid));
        accountCheck(($response['status'] === 419 || str_contains($response['location'], 'error=')) && $database->query('SELECT password_hash FROM users WHERE id = ' . $residentId)->fetchColumn() === $originalHash, 'Invalid password changes leave the current password unchanged.');
    }
    $database->exec("CREATE TRIGGER account_test_fail_audit BEFORE INSERT ON activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_ACCOUNT_AUDIT_FAILURE'");
    $response = accountRequest($route, $resident['cookie'], array_replace($form, ['full_name' => 'Should roll back']));
    accountCheck(str_contains($response['location'], 'error=') && $database->query('SELECT full_name FROM users WHERE id = ' . $residentId)->fetchColumn() === 'Updated Resident', 'A failed audit write rolls back account changes.');
    $response = accountRequest($route, $resident['cookie'], $passwordForm);
    accountCheck(str_contains($response['location'], 'error=') && $database->query('SELECT password_hash FROM users WHERE id = ' . $residentId)->fetchColumn() === $originalHash, 'A failed audit write rolls back password changes.');
    $database->exec('DROP TRIGGER account_test_fail_audit');

    if (getenv('SAGIPBRO_RESIDENT_ACCOUNT_BROWSER') === '1') {
        $script = <<<'JS'
const output = document.getElementById('result'), frame = document.getElementById('page');
let checks = 0;
const check = (condition, message) => { checks++; if (!condition) throw new Error(message); };
const load = (route) => new Promise(resolve => { frame.onload = resolve; frame.src = route; });
(async () => {
    for (const route of ['/dashboard/resident.php', '/pages/profile/index.php']) {
        await load(route);
        const doc = frame.contentDocument, page = frame.contentWindow;
        check(doc.querySelector('a[href$="pages/profile/index.php"]'), 'Resident account navigation is available');
        const logoutForm = doc.querySelector('#publicNavigation form[data-confirm-logout]');
        check(logoutForm && doc.querySelectorAll('form[data-confirm-logout]').length === 1 && logoutForm.method === 'post' && logoutForm.querySelector('[name="csrf_token"]').value, 'Sign out appears once in navigation with POST and CSRF protection');
        for (const width of [1440, 1024, 992, 768, 390, 320]) {
            frame.style.width = width + 'px';
            doc.getElementById('publicNavigation').classList.add('show');
            await new Promise(resolve => setTimeout(resolve, 100));
            check(doc.documentElement.scrollWidth <= width + 1, route + ' fits at ' + width + 'px');
            const signOutBounds = logoutForm.querySelector('button').getBoundingClientRect();
            check(signOutBounds.width > 0 && signOutBounds.left >= 0 && signOutBounds.right <= width + 1, 'Navigation sign out is visible and fits at ' + width + 'px');
            for (const input of doc.querySelectorAll('main .form-control')) {
                const bounds = input.getBoundingClientRect();
                check(bounds.width > 0 && bounds.left >= 0 && bounds.right <= width + 1, 'Account fields fit at ' + width + 'px');
            }
        }
        logoutForm.querySelector('button').click();
        await new Promise(resolve => setTimeout(resolve, 400));
        check(doc.getElementById('logoutConfirmModal').classList.contains('show'), 'Navigation sign out opens the confirmation prompt');
        page.bootstrap.Modal.getInstance(doc.getElementById('logoutConfirmModal')).hide();
        await new Promise(resolve => setTimeout(resolve, 400));
        if (route.includes('profile')) {
            for (const button of doc.querySelectorAll('[data-password-toggle]')) {
                const input = doc.getElementById(button.dataset.passwordToggle);
                button.click(); check(input.type === 'text' && button.getAttribute('aria-pressed') === 'true', 'Password can be revealed');
                button.click(); check(input.type === 'password', 'Password can be hidden');
            }
            const form = doc.querySelector('form input[name="intent"][value="profile"]').form;
            check(form.checkValidity(), 'Saved account information passes browser validation');
        }
    }
    output.textContent = JSON.stringify({ok: true, checks});
})().catch(error => { output.textContent = JSON.stringify({ok: false, checks, error: error.message}); });
JS;
        file_put_contents($directory . '/browser.html', '<!doctype html><meta charset="utf-8"><pre id="result">Running</pre><iframe id="page" title="Resident portal" style="height:1200px;border:0"></iframe><script>' . $script . '</script>');
        $chrome = getenv('SAGIPBRO_CHROME_BINARY') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        accountCheck(is_file($chrome), 'Chrome is required for optional browser checks.');
        $browser = proc_open([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--user-data-dir=' . $directory . '/chrome-profile', '--virtual-time-budget=15000', '--dump-dom', $address . '/__account_browser'], [0 => ['pipe', 'r'], 1 => ['file', $directory . '/browser-dom.html', 'wb'], 2 => ['file', $directory . '/chrome.log', 'ab']], $pipes, $project, $environment, ['bypass_shell' => true, 'create_new_console' => false]);
        if (!is_resource($browser)) throw new LogicException('Could not start headless Chrome.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 45;
        while (proc_get_status($browser)['running'] && microtime(true) < $deadline) usleep(50000);
        if (proc_get_status($browser)['running']) proc_terminate($browser);
        proc_close($browser);
        preg_match('/<pre id="result">(.*?)<\/pre>/s', (string) file_get_contents($directory . '/browser-dom.html'), $result);
        $details = json_decode(html_entity_decode($result[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        accountCheck(($details['ok'] ?? false) === true, 'Resident browser checks failed: ' . json_encode($details));
        fwrite(STDOUT, 'PASS: ' . $details['checks'] . " responsive layout and password-toggle browser checks.\n");
    }

    $response = accountRequest($route, $resident['cookie'], $passwordForm);
    $saved = $database->query('SELECT * FROM users WHERE id = ' . $residentId)->fetch();
    accountCheck($response['status'] === 302 && str_contains($response['location'], '/login.php?success=') && password_verify($passwordForm['new_password'], $saved['password_hash']) && !password_verify($originalPassword, $saved['password_hash']), 'A valid password change stores the new hash and redirects to sign in.');
    accountCheck($saved['password_changed_at'] !== null && (int) $saved['force_password_change'] === 0, 'Password changes update account security metadata.');
    $signedOut = accountRequest($route, $resident['cookie']);
    accountCheck($signedOut['status'] === 302 && str_contains($signedOut['location'], '/login.php'), 'The previous session cannot access the account after a password change.');
    $logs = $database->query('SELECT action, details FROM activity_logs WHERE user_id = ' . $residentId)->fetchAll();
    accountCheck(count(array_filter($logs, static fn (array $row): bool => $row['action'] === 'change-password')) === 1 && !str_contains(json_encode($logs), $originalPassword) && !str_contains(json_encode($logs), $passwordForm['new_password']), 'Password changes are audited without storing passwords in logs.');
    accountCheck($database->query('SELECT * FROM users WHERE id <> ' . $residentId . ' ORDER BY id')->fetchAll() === $otherAccounts, 'All other accounts remain unchanged.');
    accountCheck(!preg_match('/PHP (?:Warning|Fatal error)|Uncaught (?:Error|Exception)/', (string) file_get_contents($directory . '/server.log')), 'Account requests emit no PHP warnings or fatal errors.');
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . ($error instanceof LogicException ? $error->getMessage() : get_class($error) . ' at line ' . $error->getLine()) . "\n");
} finally {
    if (is_resource($server)) {
        if (proc_get_status($server)['running']) proc_terminate($server);
        proc_close($server);
    }
    if ($createdDatabase && $database instanceof PDO && preg_match('/\Asagipbro_account_test_[a-f0-9]{20}\z/', $testDatabase)) $database->exec('DROP DATABASE `' . $testDatabase . '`');
    // Delete only this run's validated private temporary directory.
    if ($directory !== null) {
        $resolved = realpath($directory);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved !== false && $temporaryRoot !== false && str_starts_with(strtolower($resolved), strtolower($temporaryRoot . DIRECTORY_SEPARATOR . 'sagipbro-account-')) && preg_match('/\Asagipbro-account-[a-f0-9]{16}\z/', basename($resolved))) {
            $remove = static function (string $target) use (&$remove, $resolved): void {
                $absolute = realpath($target);
                if ($absolute === false || (strcasecmp($absolute, $resolved) !== 0 && !str_starts_with(strtolower($absolute), strtolower($resolved . DIRECTORY_SEPARATOR)))) return;
                if (is_dir($target) && !is_link($target)) {
                    foreach (scandir($target) as $entry) if ($entry !== '.' && $entry !== '..') $remove($target . DIRECTORY_SEPARATOR . $entry);
                    @rmdir($target);
                } else @unlink($target);
            };
            $remove($resolved);
        }
    }
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED' : 'PASS') . ': ' . $checks . " resident account HTTP assertions; application data untouched.\n");
exit($failed ? 1 : 0);
