<?php
declare(strict_types=1);

function run_migrations(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_key VARCHAR(120) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $key = '20260922_01_day_events';
    $q = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration_key=?');
    $q->execute([$key]);
    if ((int)$q->fetchColumn() > 0) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_day_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        day_id BIGINT UNSIGNED NOT NULL,
        event_no INT NOT NULL,
        label VARCHAR(80) NOT NULL,
        status ENUM('planned','submitted') NOT NULL DEFAULT 'planned',
        seller_note TEXT NULL,
        submitted_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        FOREIGN KEY(day_id) REFERENCES order_days(id) ON DELETE CASCADE,
        UNIQUE(day_id,event_no),
        INDEX(day_id,status,event_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='day_uploads' AND COLUMN_NAME='event_id'");
    if ((int)$q->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE day_uploads ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER day_id");
    }

    $q = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='day_uploads' AND INDEX_NAME='idx_day_uploads_event'");
    if ((int)$q->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE day_uploads ADD INDEX idx_day_uploads_event(event_id)");
    }

    $q = $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='day_uploads' AND CONSTRAINT_NAME='fk_day_uploads_event'");
    if ((int)$q->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE day_uploads
            ADD CONSTRAINT fk_day_uploads_event
            FOREIGN KEY(event_id) REFERENCES order_day_events(id) ON DELETE CASCADE");
    }

    $pdo->prepare('INSERT INTO schema_migrations(migration_key) VALUES(?)')->execute([$key]);
}
