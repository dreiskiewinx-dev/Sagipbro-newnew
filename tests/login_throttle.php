<?php
declare(strict_types=1);

/** php tests/login_throttle.php: temporary database and real parallel PHP workers. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require_once __DIR__ . '/../config/login_throttle.php';
require_once __DIR__ . '/../database/login_throttle_migration.php';
$project = dirname(__DIR__);
$name = 'sagipbro_throttle_test_' . bin2hex(random_bytes(10));
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sagipbro-throttle-' . bin2hex(random_bytes(8));
$db = null; $created = false; $checks = 0; $failed = false;

function throttleCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new LogicException($message);
}

function throttleFailure(PDO $db, string $account, string $ip): bool
{
    $throttle = new LoginThrottle($db);
    if (!$throttle->beginAttempt($account, $ip)) return false;
    $throttle->finishAttempt(false);
    return true;
}

function throttleParallel(string $mode, array $environment): int
{
    global $project, $directory;
    $workers = [];
    try {
        for ($i = 0; $i < 8; ++$i) {
            $worker = proc_open([PHP_BINARY, $directory . '/worker.php', $mode, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project, $environment,
                ['bypass_shell' => true, 'create_new_console' => false]);
            if (!is_resource($worker)) throw new LogicException('Cannot start parallel throttle worker.');
            fclose($pipes[0]);
            $workers[] = ['process' => $worker, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        }
        $allowed = 0;
        foreach ($workers as &$worker) {
            $output = stream_get_contents($worker['stdout']); $error = stream_get_contents($worker['stderr']);
            fclose($worker['stdout']); fclose($worker['stderr']);
            $exit = proc_close($worker['process']); $worker['process'] = null;
            throttleCheck($exit === 0 && $error === '' && preg_match('/\A[01]+\z/', $output), 'Parallel workers complete without errors.');
            $allowed += substr_count($output, '1');
        }
        unset($worker);
        return $allowed;
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
            foreach (['stdout', 'stderr'] as $pipe) if (is_resource($worker[$pipe])) fclose($worker[$pipe]);
        }
    }
}

try {
    $host = getenv('SAGIPBRO_DB_HOST') ?: '127.0.0.1'; $port = getenv('SAGIPBRO_DB_PORT') ?: '3306';
    throttleCheck(ctype_digit($port) && strpbrk($host, ";\r\n") === false, 'Invalid database host/port.');
    $user = getenv('SAGIPBRO_DB_USER'); $password = getenv('SAGIPBRO_DB_PASSWORD');
    $db = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user === false ? 'root' : $user, $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('CREATE DATABASE `' . $name . '`'); $created = true; $db->exec('USE `' . $name . '`');
    migrateLoginThrottle($db);
    for ($i = 0; $i < 5; ++$i) throttleCheck(throttleFailure($db, 'user:1', '203.0.113.' . ($i + 1)), 'An account permits its first five failures across different IPs.');
    throttleCheck(!throttleFailure($db, 'user:1', '203.0.113.99'), 'Changing IP cannot bypass the account cooldown.');
    $before = $db->query("SELECT * FROM login_rate_limits WHERE bucket_scope='account'")->fetch();
    migrateLoginThrottle($db);
    throttleCheck($db->query("SELECT * FROM login_rate_limits WHERE bucket_scope='account'")->fetch() === $before, 'Rerunning the migration preserves counters and cooldowns.');
    throttleCheck(!throttleFailure($db, 'user:1', '203.0.113.98'), 'A blocked retry is rejected.');
    throttleCheck($db->query("SELECT * FROM login_rate_limits WHERE bucket_scope='account'")->fetch() === $before, 'Blocked retries do not extend cooldowns or increase counters.');
    $db->exec("UPDATE login_rate_limits SET blocked_until=UNIX_TIMESTAMP()-1 WHERE bucket_scope='account'");
    throttleCheck(throttleFailure($db, 'user:1', '203.0.113.99'), 'Expired cooldowns automatically permit a new attempt.');
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='account'")->fetchColumn() === 1, 'Expired cooldowns start a fresh failure window.');
    $success = new LoginThrottle($db);
    throttleCheck($success->beginAttempt('user:1', '203.0.113.99'), 'Successful credentials can be checked below the limit.'); $success->finishAttempt(true);
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='account'")->fetchColumn() === 0, 'Success clears the account failure counter.');
    $ipHash = hash('sha256', bin2hex(inet_pton('203.0.113.99')));
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='ip' AND bucket_key='{$ipHash}'")->fetchColumn() === 1, 'Success does not clear IP failures.');
    $db->exec('DELETE FROM login_rate_limits');
    for ($i = 0; $i < 30; ++$i) throttleCheck(throttleFailure($db, 'username:unknown-' . $i, '203.0.113.50'), 'Each unknown username contributes to the IP limit.');
    throttleCheck(!throttleFailure($db, 'user:2', '203.0.113.50'), 'Changing usernames cannot bypass an IP cooldown.');
    throttleCheck(throttleFailure($db, 'user:2', '203.0.113.51'), 'An unrelated IP and account remain available.');
    $db->exec('DELETE FROM login_rate_limits');
    throttleCheck(throttleFailure($db, 'user:3', '2001:db8::1'), 'IPv6 failures are counted.');
    $v6 = new LoginThrottle($db);
    throttleCheck($v6->beginAttempt('user:3', '2001:0db8:0000:0000:0000:0000:0000:0001'), 'Equivalent IPv6 addresses use the same bucket.'); $v6->finishAttempt(false);
    throttleCheck((int) $db->query("SELECT COUNT(*) FROM login_rate_limits WHERE bucket_scope='ip'")->fetchColumn() === 1, 'IPv6 textual variations do not create extra IP buckets.');
    $db->exec('UPDATE login_rate_limits SET window_started_at=UNIX_TIMESTAMP()-901');
    throttleCheck(throttleFailure($db, 'user:3', '2001:db8::1'), 'An expired observation window permits attempts.');
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='account'")->fetchColumn() === 1, 'An expired window resets old failures.');
    $cancel = new LoginThrottle($db); $cancel->beginAttempt('cancelled', '203.0.113.60'); $cancel->cancelAttempt();
    throttleCheck(!$db->inTransaction(), 'Cancellation releases the database transaction.');
    $db->exec('DELETE FROM login_rate_limits');
    throttleCheck(mkdir($directory, 0700), 'Create a private worker directory.');
    $worker = '<?php require ' . var_export($project . '/config/connection.php', true) . '; require ' . var_export($project . '/config/login_throttle.php', true) . '; $db=sagipbroDatabase(); for($i=0;$i<($argv[1]==="account"?1:4);$i++){ $limiter=new LoginThrottle($db); $identity=$argv[1]==="account"?"parallel-account":"parallel-ip-".$argv[2]."-".$i; if($limiter->beginAttempt($identity,$argv[1]==="account"?"203.0.113.70":"203.0.113.71")){usleep(70000);$limiter->finishAttempt(false);echo "1";}else{echo "0";} }';
    file_put_contents($directory . '/worker.php', $worker);
    $environment = getenv(); $environment['SAGIPBRO_DB_NAME'] = $name;
    throttleCheck(throttleParallel('account', $environment) === 5, 'Eight simultaneous attempts permit exactly five failures on one account.');
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='account'")->fetchColumn() === 5, 'Parallel account updates never lose increments.');
    $db->exec('DELETE FROM login_rate_limits');
    throttleCheck(throttleParallel('ip', $environment) === 30, 'Thirty-two parallel attempts across accounts permit exactly thirty IP failures.');
    throttleCheck((int) $db->query("SELECT failures FROM login_rate_limits WHERE bucket_scope='ip'")->fetchColumn() === 30, 'Parallel IP updates never lose increments.');
    $db->exec('RENAME TABLE login_rate_limits TO unavailable_limits');
    try { (new LoginThrottle($db))->beginAttempt('unavailable', '203.0.113.80'); throw new LogicException('Missing throttle storage cannot allow login.'); }
    catch (PDOException $e) { throttleCheck(!$db->inTransaction(), 'Storage failure rolls back the transaction.'); }
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . ($e instanceof LogicException ? $e->getMessage() : get_class($e) . ' at line ' . $e->getLine()) . "\n");
} finally {
    if ($created && $db instanceof PDO && preg_match('/\Asagipbro_throttle_test_[a-f0-9]{20}\z/', $name)) $db->exec('DROP DATABASE `' . $name . '`');
    $resolved = realpath($directory); $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporaryRoot !== false && str_starts_with(strtolower($resolved), strtolower($temporaryRoot . DIRECTORY_SEPARATOR . 'sagipbro-throttle-')) && preg_match('/\Asagipbro-throttle-[a-f0-9]{16}\z/', basename($resolved))) {
        foreach (new DirectoryIterator($resolved) as $file) if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
        rmdir($resolved);
    }
}
fwrite($failed ? STDERR : STDOUT, ($failed ? 'FAILED' : 'PASS') . ': ' . $checks . " database login-throttle assertions; application data untouched.\n");
exit($failed ? 1 : 0);
