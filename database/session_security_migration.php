<?php
declare(strict_types=1);

function migrateSessionSecurity(PDO $db): void
{
    $column = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='user_sessions' AND COLUMN_NAME='last_activity_at'")->fetchColumn();
    if ($column === false) {
        $db->exec('ALTER TABLE user_sessions ADD COLUMN last_activity_at DATETIME NULL AFTER last_seen_at');
        $column = 'YES';
    }
    if ($column === 'YES') {
        $db->exec('UPDATE user_sessions SET last_activity_at=COALESCE(last_seen_at, created_at) WHERE last_activity_at IS NULL');
        $db->exec('ALTER TABLE user_sessions MODIFY COLUMN last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
}
