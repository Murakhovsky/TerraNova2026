CREATE TABLE IF NOT EXISTS tn_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(160) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'manager_note'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN manager_note TEXT NULL AFTER meta_description', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'source_note'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN source_note VARCHAR(500) NULL AFTER manager_note', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'status_note'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN status_note VARCHAR(500) NULL AFTER source_note', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'status_changed_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN status_changed_at DATETIME NULL AFTER status_note', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tn_property_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type ENUM('note', 'status_change', 'details_update', 'media_update', 'moderation', 'system') NOT NULL DEFAULT 'note',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    old_value VARCHAR(500) NULL,
    new_value VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_property_activities_property (property_id, created_at),
    KEY idx_tn_property_activities_user (user_id),
    CONSTRAINT fk_tn_property_activities_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_activities_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260711_000008_property_operations');
