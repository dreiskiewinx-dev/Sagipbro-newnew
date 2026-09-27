<?php

require_once "../../config/database.php";
require_once "../../config/session.php";

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

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    header("Location: ../../login.php?error=Please fill in all fields");
    exit;
}

if (loginIsThrottled($username)) {
    header("Location: ../../login.php?error=Too many attempts. Please try again later");
    exit;
}

$sql = "SELECT * FROM users WHERE username = :username AND status = 'Active' LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ":username" => $username
]);

$user = $stmt->fetch();

if ($user && password_verify($password, $user["password_hash"])) {

    clearLoginFailures($username);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $conn->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $rehash->execute([
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $user['id']
        ]);
    }
    session_regenerate_id(true);
    csrfToken();
    $_SESSION["user_id"] = $user["id"];
    $_SESSION["full_name"] = $user["full_name"];
    $_SESSION["username"] = $user["username"];
    $_SESSION["role"] = $user["role"];
    $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    $sessionHash = hash('sha256', session_id());
    $presence = $conn->prepare(
        'INSERT INTO user_sessions (session_hash, user_id, last_seen_at, signed_out_at)
         VALUES (?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = NOW(), signed_out_at = NULL'
    );
    $presence->execute([$sessionHash, $user['id']]);

    $dashboard = $_SESSION["role"] === 'resident' ? 'resident.php' : ($_SESSION["role"] === 'volunteer' ? 'volunteer.php' : 'admin.php');
    header("Location: ../../dashboard/" . $dashboard);
    exit;
}

recordLoginFailure($username);
header("Location: ../../login.php?error=Invalid username or password");
exit;
