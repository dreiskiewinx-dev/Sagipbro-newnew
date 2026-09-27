<?php

if (session_status() === PHP_SESSION_NONE) {
    $configuredSessionPath = trim((string) getenv('SAGIPBRO_SESSION_PATH'));
    $sessionPath = $configuredSessionPath !== ''
        ? $configuredSessionPath
        : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sagipbro-sessions';

    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create the SAGIPBRO session directory.');
    }
    if (!is_writable($sessionPath)) {
        throw new RuntimeException('The SAGIPBRO session directory is not writable.');
    }

    session_save_path($sessionPath);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function appUrl(string $path = ''): string
{
    $configuredBase = trim((string) getenv('SAGIPBRO_BASE_URL'));
    if ($configuredBase !== '') {
        return rtrim($configuredBase, '/') . '/' . ltrim($path, '/');
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $base = '';
    if ($projectRoot && $documentRoot) {
        $project = str_replace('\\', '/', $projectRoot);
        $document = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if (str_starts_with(strtolower($project), strtolower($document))) {
            $base = substr($project, strlen($document));
        }
    }

    return '/' . trim($base . '/' . ltrim($path, '/'), '/');
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: ' . appUrl('login.php'));
        exit;
    }
}

function requireRole($roles)
{
    requireLogin();

    if (!in_array($_SESSION['role'], (array) $roles, true)) {
        header('Location: ' . appUrl('index.php'));
        exit;
    }
}

function currentUserId()
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($submitted === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(419);
        exit('Invalid security token. Please go back and try again.');
    }
}

function loginThrottleKey(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function loginIsThrottled(string $username): bool
{
    $entry = $_SESSION['login_attempts'][loginThrottleKey($username)] ?? null;
    return is_array($entry) && ($entry['count'] ?? 0) >= 5 && time() - ($entry['started'] ?? 0) < 900;
}

function recordLoginFailure(string $username): void
{
    $key = loginThrottleKey($username);
    $entry = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'started' => time()];
    if (time() - $entry['started'] >= 900) {
        $entry = ['count' => 0, 'started' => time()];
    }
    $entry['count']++;
    $_SESSION['login_attempts'][$key] = $entry;
}

function clearLoginFailures(string $username): void
{
    unset($_SESSION['login_attempts'][loginThrottleKey($username)]);
}
