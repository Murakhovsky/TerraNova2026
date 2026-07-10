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
      AND TABLE_NAME = 'tn_property_submissions'
      AND COLUMN_NAME = 'property_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_property_submissions ADD COLUMN property_id BIGINT UNSIGNED NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_property_submissions'
      AND COLUMN_NAME = 'reviewed_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_property_submissions ADD COLUMN reviewed_at DATETIME NULL AFTER source_page', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_property_submissions'
      AND COLUMN_NAME = 'review_note'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_property_submissions ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewed_at', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_property_submissions'
      AND INDEX_NAME = 'idx_tn_property_submissions_property'
);
SET @sql = IF(@index_exists = 0, 'ALTER TABLE tn_property_submissions ADD KEY idx_tn_property_submissions_property (property_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260708_000003_property_submission_review');
