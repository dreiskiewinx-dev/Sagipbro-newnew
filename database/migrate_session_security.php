<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run database migrations from the command line.'); }
require_once __DIR__ . '/../config/connection.php';
require_once __DIR__ . '/session_security_migration.php';
try {
    $db = sagipbroDatabase();
    migrateSessionSecurity($db);
    echo 'Session security is ready in ' . $db->query('SELECT DATABASE()')->fetchColumn() . ". Existing records were preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Session security migration failed. Check the configured database and base schema.\n");
    exit(1);
}
