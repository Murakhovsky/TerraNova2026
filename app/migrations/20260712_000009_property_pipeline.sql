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
      AND COLUMN_NAME = 'operational_stage'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN operational_stage VARCHAR(40) NOT NULL DEFAULT "intake" AFTER status_changed_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'next_action_title'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN next_action_title VARCHAR(180) NULL AFTER operational_stage', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'next_action_due_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN next_action_due_at DATETIME NULL AFTER next_action_title', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'next_action_note'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN next_action_note VARCHAR(500) NULL AFTER next_action_due_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND INDEX_NAME = 'idx_tn_properties_operational_stage'
);
SET @sql = IF(@index_exists = 0, 'ALTER TABLE tn_properties ADD KEY idx_tn_properties_operational_stage (operational_stage, updated_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND INDEX_NAME = 'idx_tn_properties_next_action'
);
SET @sql = IF(@index_exists = 0, 'ALTER TABLE tn_properties ADD KEY idx_tn_properties_next_action (next_action_due_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260712_000009_property_pipeline');
