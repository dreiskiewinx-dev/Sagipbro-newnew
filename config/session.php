<?php

require_once __DIR__ . '/config.php';

const SAGIPBRO_SESSION_IDLE_SECONDS = 1200;
const SAGIPBRO_SESSION_MAX_SECONDS = 28800;
const SAGIPBRO_LOGIN_ROLES = ['admin', 'official', 'volunteer', 'resident'];

function dashboardPath(?string $role = null): string
{
    return match ($role ?? ($_SESSION['role'] ?? '')) {
        'admin', 'official' => 'dashboard/admin.php',
        'volunteer' => 'dashboard/volunteer.php',
        'resident' => 'dashboard/resident.php',
        default => 'login.php',
    };
}

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

function isLoggedIn(): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Validate once per request, before page/API helpers release the session lock.
    static $validatedSession = null;
    $sessionKey = session_id() . ':' . (string) $_SESSION['user_id'] . ':' . ($_SESSION['role'] ?? '');
    if (!in_array($_SESSION['role'] ?? '', SAGIPBRO_LOGIN_ROLES, true)) {
        invalidateLoginSession();
        return false;
    }
    if ($validatedSession === $sessionKey) {
        return true;
    }

    require_once __DIR__ . '/connection.php';
    try {
        $statement = sagipbroDatabase()->prepare(
            "SELECT u.id, u.role, u.force_password_change, u.password_hash,
                    UNIX_TIMESTAMP(s.created_at) AS signed_in_at,
                    UNIX_TIMESTAMP(s.last_activity_at) AS activity_at,
                    UNIX_TIMESTAMP() AS server_now FROM users u
             INNER JOIN user_sessions s ON s.user_id = u.id
             WHERE u.id = ? AND u.role IN ('admin', 'official', 'volunteer', 'resident') AND u.status = 'Active'
               AND s.session_hash = ? AND s.signed_out_at IS NULL
             LIMIT 1"
        );
        $statement->execute([(int) $_SESSION['user_id'], hash('sha256', session_id())]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);
        $authorized = $record !== false;
        if ($record) {
            $now = (int) $record['server_now'];
            $expired = $now >= (int) $record['signed_in_at'] + SAGIPBRO_SESSION_MAX_SECONDS
                || $now >= (int) $record['activity_at'] + SAGIPBRO_SESSION_IDLE_SECONDS;
            $version = hash('sha256', $record['password_hash']);
            $credentialChanged = isset($_SESSION['credential_version']) && !hash_equals($_SESSION['credential_version'], $version);
            $roleChanged = $record['role'] !== $_SESSION['role'];
            if ($expired || $credentialChanged || $roleChanged) {
                $revoke = sagipbroDatabase()->prepare('UPDATE user_sessions SET signed_out_at=NOW() WHERE session_hash=? AND user_id=? AND signed_out_at IS NULL');
                $revoke->execute([hash('sha256', session_id()), (int) $_SESSION['user_id']]);
                $authorized = false;
            } else {
                $_SESSION['credential_version'] = $version;
                $_SESSION['force_password_change'] = (bool) $record['force_password_change'];
                setSessionTiming($record);
            }
        }
    } catch (Throwable $e) {
        error_log('Account session validation unavailable (' . get_class($e) . ').');
        http_response_code(503);
        exit('Authentication temporarily unavailable. Please try again later.');
    }

    if (!$authorized) {
        invalidateLoginSession();
        return false;
    }
    $validatedSession = $sessionKey;
    return true;
}

function setSessionTiming(array $record): void
{
    $_SESSION['session_timing'] = [
        'idle_expires_at' => (int) $record['activity_at'] + SAGIPBRO_SESSION_IDLE_SECONDS,
        'absolute_expires_at' => (int) $record['signed_in_at'] + SAGIPBRO_SESSION_MAX_SECONDS,
        'server_now' => (int) $record['server_now'],
    ];
}

function passwordChangeRequired(): bool
{
    return !empty($_SESSION['force_password_change']);
}

function isPasswordChangeRequest(): bool
{
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === false) return false;
    $root = dirname(__DIR__);
    if ($script === realpath($root . '/pages/profile/change-password.php')) return true;
    return $script === realpath($root . '/pages/profile/index.php')
        && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['intent'] ?? '') === 'password';
}

/** Only foreground page navigation or the explicit activity endpoint calls this. */
function touchSessionActivity(): bool
{
    $db = sagipbroDatabase();
    $params = [hash('sha256', session_id()), (int) currentUserId(), SAGIPBRO_SESSION_MAX_SECONDS, SAGIPBRO_SESSION_IDLE_SECONDS];
    $statement = $db->prepare('UPDATE user_sessions SET last_activity_at=NOW(), last_seen_at=NOW()
        WHERE session_hash=? AND user_id=? AND signed_out_at IS NULL
          AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
          AND last_activity_at > DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $statement->execute($params);
    // Read after the conditional update, also handling two requests in the same second.
    $statement = $db->prepare('SELECT UNIX_TIMESTAMP(created_at) AS signed_in_at,
        UNIX_TIMESTAMP(last_activity_at) AS activity_at, UNIX_TIMESTAMP() AS server_now
        FROM user_sessions WHERE session_hash=? AND user_id=? AND signed_out_at IS NULL
          AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
          AND last_activity_at > DATE_SUB(NOW(), INTERVAL ? SECOND)');
    $statement->execute($params);
    $record = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$record) { invalidateLoginSession(); return false; }
    setSessionTiming($record);
    return true;
}

function invalidateLoginSession(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
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
    header('Cache-Control: no-store, private');
    if (!isLoggedIn()) {
        header('Location: ' . appUrl('login.php'));
        exit;
    }

    if (passwordChangeRequired() && !isPasswordChangeRequest()) {
        header('Location: ' . appUrl('pages/profile/change-password.php'));
        exit;
    }
    static $activityRecorded = false;
    if (!$activityRecorded) {
        try {
            if (!touchSessionActivity()) { header('Location: ' . appUrl('login.php')); exit; }
        } catch (Throwable $e) {
            error_log('Session activity unavailable (' . get_class($e) . ').');
            http_response_code(503);
            exit('Authentication temporarily unavailable. Please try again later.');
        }
        $activityRecorded = true;
    }

    // Read-only page requests do not need to hold PHP's exclusive session-file
    // lock while queries and HTML render. Releasing it here prevents background
    // notification/presence calls from queueing rapid page refreshes.
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
        && session_status() === PHP_SESSION_ACTIVE) {
        csrfToken();
        session_write_close();
    }
}

function requireRole($roles)
{
    requireLogin();

    if (!in_array($_SESSION['role'], (array) $roles, true)) {
        header('Location: ' . appUrl(dashboardPath()));
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
