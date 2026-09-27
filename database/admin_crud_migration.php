<?php
declare(strict_types=1);

/** Add fields represented by the admin forms without replacing existing records. */
function migrateAdminCrud(PDO $db): void
{
    $columns = $db->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $tableColumns = static function (string $table) use ($columns): array {
        $columns->execute([$table]);
        return $columns->fetchAll(PDO::FETCH_COLUMN);
    };
    $add = static function (string $table, string $column, string $definition) use ($db, $tableColumns): void {
        if (!in_array($column, $tableColumns($table), true)) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    foreach (['users', 'residents', 'resources', 'evacuation_centers', 'distributions', 'announcements', 'activity_logs'] as $table) {
        if (!$tableColumns($table)) {
            throw new RuntimeException("Missing required table: {$table}. Import database/sagipbro.sql first.");
        }
    }

    $add('users', 'email', 'VARCHAR(160) NULL AFTER `username`');
    $add('users', 'force_password_change', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    $add('users', 'position', 'VARCHAR(150) NULL AFTER `email`');
    $add('users', 'contact', 'VARCHAR(30) NULL AFTER `position`');
    $add('users', 'language', "VARCHAR(20) NOT NULL DEFAULT 'English' AFTER `contact`");
    $add('users', 'notify_stock', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `language`');
    $add('users', 'notify_centers', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_stock`');
    $add('users', 'notify_digest', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `notify_centers`');
    $add('users', 'last_login_at', 'DATETIME NULL AFTER `force_password_change`');
    $add('users', 'password_changed_at', 'DATETIME NULL AFTER `last_login_at`');
    $add('residents', 'address', 'VARCHAR(255) NULL AFTER `contact_no`');
    $add('resources', 'location', 'VARCHAR(255) NULL AFTER `low_stock_threshold`');
    $add('resources', 'notes', 'TEXT NULL AFTER `location`');
    $add('evacuation_centers', 'contact_person', 'VARCHAR(150) NULL AFTER `occupants`');
    $add('evacuation_centers', 'contact_number', 'VARCHAR(30) NULL AFTER `contact_person`');
    $add('evacuation_centers', 'notes', 'TEXT NULL AFTER `contact_number`');
    $add('distributions', 'recipient_reference', 'VARCHAR(80) NULL AFTER `recipient_resident_id`');
    $add('distributions', 'location', 'VARCHAR(255) NULL AFTER `distributed_at`');
    $add('distributions', 'remarks', 'TEXT NULL AFTER `location`');
    $add('distributions', 'status', "ENUM('Completed', 'Pending review') NOT NULL DEFAULT 'Completed' AFTER `remarks`");
    $add('announcements', 'category', "VARCHAR(100) NOT NULL DEFAULT 'Advisory' AFTER `body`");
    $add('announcements', 'audience', "VARCHAR(100) NOT NULL DEFAULT 'All residents' AFTER `category`");

    $db->exec(
        'CREATE TABLE IF NOT EXISTS user_notification_state (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            last_seen_at DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_notification_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS user_sessions (
            session_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL,
            signed_out_at DATETIME NULL,
            INDEX idx_user_sessions_presence (user_id, signed_out_at, last_seen_at),
            CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
}
