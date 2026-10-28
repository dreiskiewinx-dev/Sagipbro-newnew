<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run database migrations from the command line.');
}
require_once __DIR__ . '/../config/connection.php';
require_once __DIR__ . '/login_throttle_migration.php';
try {
    $db = sagipbroDatabase();
    // Refuse to create a security table in a database without the application schema.
    $db->query('SELECT id FROM users LIMIT 0');
    migrateLoginThrottle($db);
    echo 'Login rate limits are ready in ' . $db->query('SELECT DATABASE()')->fetchColumn() . ". Existing records were preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Login rate-limit migration failed. Check the database configuration and imported schema.\n");
    exit(1);
}
