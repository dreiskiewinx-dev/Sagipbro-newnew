<?php
declare(strict_types=1);

function migrateLoginThrottle(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        bucket_scope ENUM('account', 'ip') NOT NULL,
        bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        failures INT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at BIGINT UNSIGNED NOT NULL,
        blocked_until BIGINT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (bucket_scope, bucket_key),
        INDEX idx_login_rate_limits_updated (updated_at)
    ) ENGINE=InnoDB");
}
