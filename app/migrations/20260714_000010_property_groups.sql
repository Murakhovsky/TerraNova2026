CREATE TABLE IF NOT EXISTS tn_property_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(220) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    group_type ENUM('address', 'building', 'complex', 'project', 'location') NOT NULL DEFAULT 'address',
    location_id INT UNSIGNED NOT NULL,
    address VARCHAR(255) NULL,
    description TEXT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_groups_slug (slug),
    KEY idx_tn_property_groups_location (location_id, status),
    KEY idx_tn_property_groups_type (group_type, status),
    CONSTRAINT fk_tn_property_groups_location FOREIGN KEY (location_id) REFERENCES tn_locations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'property_group_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE tn_properties ADD COLUMN property_group_id BIGINT UNSIGNED NULL AFTER location_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND INDEX_NAME = 'idx_tn_properties_group'
);
SET @sql = IF(@index_exists = 0, 'ALTER TABLE tn_properties ADD KEY idx_tn_properties_group (property_group_id, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND CONSTRAINT_NAME = 'fk_tn_properties_group'
);
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE tn_properties ADD CONSTRAINT fk_tn_properties_group FOREIGN KEY (property_group_id) REFERENCES tn_property_groups (id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO tn_locations (country_code, region, city, district, slug, sort_order)
VALUES
    ('UA', 'Львівська область', 'Львів', NULL, 'lviv', 10),
    ('UA', 'Львівська область', 'Брюховичі', NULL, 'briukhovychi', 20),
    ('UA', 'Львівська область', 'Ременів', NULL, 'remeniv', 30)
ON DUPLICATE KEY UPDATE
    region = VALUES(region),
    city = VALUES(city),
    district = VALUES(district),
    sort_order = VALUES(sort_order),
    is_active = 1;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260714_000010_property_groups');
