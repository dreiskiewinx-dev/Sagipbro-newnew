<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run database migrations from the command line.');
}

require_once __DIR__ . '/../config/connection.php';
require_once __DIR__ . '/admin_crud_migration.php';

try {
    migrateAdminCrud(sagipbroDatabase());
    echo "Admin CRUD migration complete.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Admin CRUD migration failed.\n" . $e->getMessage() . "\n");
    exit(1);
}
