SET @image_url_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_property_groups'
      AND COLUMN_NAME = 'image_url'
);
SET @sql = IF(@image_url_exists = 0, 'ALTER TABLE tn_property_groups ADD COLUMN image_url VARCHAR(500) NULL AFTER description', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260719_000014_property_group_visuals');
