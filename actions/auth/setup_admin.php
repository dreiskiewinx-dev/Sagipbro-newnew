<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this setup script from the command line.");
}
require_once __DIR__ . '/../../config/password_policy.php';

$full_name = getenv('SAGIPBRO_ADMIN_NAME') ?: 'SAGIPBRO Administrator';
$username = getenv('SAGIPBRO_ADMIN_USERNAME');
$password = getenv('SAGIPBRO_ADMIN_PASSWORD');
if (!$username || !$password) {
    fwrite(STDERR, "Set SAGIPBRO_ADMIN_USERNAME and SAGIPBRO_ADMIN_PASSWORD (at least 8 characters) first.\n");
    exit(1);
}
try {
    $error = sagipbroPasswordPolicyError($password);
    if ($error !== null) { fwrite(STDERR, $error . "\n"); exit(1); }
} catch (PasswordScreeningUnavailable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
require_once __DIR__ . '/../../config/database.php';

$password_hash = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users
        (full_name, username, password_hash, role, status)
        VALUES
        (:full_name, :username, :password_hash, 'admin', 'Active')";

$stmt = $conn->prepare($sql);

$stmt->execute([
    ":full_name" => $full_name,
    ":username" => $username,
    ":password_hash" => $password_hash
]);

echo "Admin account created successfully.";

?>
