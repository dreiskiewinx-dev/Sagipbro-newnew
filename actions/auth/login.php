<?php

require_once "../../config/database.php";
require_once "../../config/session.php";
require_once __DIR__ . '/../../config/login_throttle.php';

function captchaPassed(): bool
{
    $secret = getenv('SAGIPBRO_RECAPTCHA_SECRET');
    if (!$secret) {
        return ($_POST['not_robot'] ?? '') === '1';
    }

    $response = trim((string) ($_POST['g-recaptcha-response'] ?? ''));
    if ($response === '') {
        return false;
    }

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'secret' => $secret,
            'response' => $response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]),
        'timeout' => 5
    ]]);
    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    $verification = $result ? json_decode($result, true) : null;
    return !empty($verification['success']);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../../login.php");
    exit;
}

verifyCsrf();

if (!captchaPassed()) {
    header("Location: ../../login.php?error=Please confirm that you are not a robot");
    exit;
}

$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

if ($username === "" || $password === "") {
    header("Location: ../../login.php?error=Please fill in all fields");
    exit;
}

$throttle = new LoginThrottle($conn);
try {
    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    // Stored IDs group every username spelling accepted by the database collation.
    $identity = $user ? 'user:' . $user['id'] : 'username:' . mb_strtolower($username, 'UTF-8');
    if (!$throttle->beginAttempt($identity, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        header('Location: ../../login.php?error=Unable to sign in. Please try again later');
        exit;
    }
    $authenticated = $user && in_array($user['role'], SAGIPBRO_LOGIN_ROLES, true) && $user['status'] === 'Active'
        && password_verify($password, $user['password_hash']);
    $throttle->finishAttempt($authenticated);
} catch (Throwable $e) {
    $throttle->cancelAttempt();
    error_log('Login attempt protection unavailable (' . get_class($e) . ').');
    http_response_code(503);
    exit('Unable to sign in. Please try again later.');
}

if ($authenticated) {

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $previousHash = $user['password_hash'];
        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $rehash = $conn->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND BINARY password_hash = :previous_hash');
        $rehash->execute([
            ':password_hash' => $user['password_hash'],
            ':id' => $user['id'],
            ':previous_hash' => $previousHash
        ]);
        if (!$rehash->rowCount()) {
            header('Location: ../../login.php?error=Invalid username or password');
            exit;
        }
    }
    session_regenerate_id(true);
    csrfToken();
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["role"] = $user["role"];
    $_SESSION['credential_version'] = hash('sha256', $user['password_hash']);
    $_SESSION['force_password_change'] = (bool) $user['force_password_change'];
    $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    $sessionHash = hash('sha256', session_id());
    $presence = $conn->prepare(
        'INSERT INTO user_sessions (session_hash, user_id, last_seen_at, last_activity_at, signed_out_at)
         VALUES (?, ?, NOW(), NOW(), NULL)'
    );
    $presence->execute([$sessionHash, $user['id']]);

    header('Location: ../../' . (passwordChangeRequired() ? 'pages/profile/change-password.php' : dashboardPath()));
    exit;
}

header("Location: ../../login.php?error=Invalid username or password");
exit;
