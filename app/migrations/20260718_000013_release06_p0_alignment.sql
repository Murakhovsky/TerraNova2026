ALTER TABLE tn_properties
    MODIFY status ENUM('draft', 'submitted', 'moderation', 'published', 'active', 'hidden', 'reserved', 'sold', 'archived', 'needs_update') NOT NULL DEFAULT 'draft';

ALTER TABLE tn_property_submissions
    MODIFY status ENUM('draft', 'submitted', 'new', 'review', 'in_review', 'needs_changes', 'accepted', 'approved', 'published', 'rejected', 'spam') NOT NULL DEFAULT 'new';

ALTER TABLE tn_leads
    MODIFY status ENUM('new', 'contacted', 'qualified', 'viewing', 'viewing_planned', 'negotiation', 'won', 'lost', 'spam', 'closed') NOT NULL DEFAULT 'new';

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'min_price_amount'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN min_price_amount DECIMAL(14,2) NULL AFTER price_period',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'commission_type'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN commission_type ENUM("none", "percent", "fixed", "included") NOT NULL DEFAULT "none" AFTER min_price_amount',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'commission_value'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN commission_value DECIMAL(14,2) NULL AFTER commission_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'visibility'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN visibility ENUM("public", "team", "partners", "private") NOT NULL DEFAULT "public" AFTER commission_value',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'sale_priority'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN sale_priority ENUM("low", "normal", "high", "urgent") NOT NULL DEFAULT "normal" AFTER visibility',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'reserved_until'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN reserved_until DATETIME NULL AFTER sale_priority',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'reserved_by_case_id'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN reserved_by_case_id BIGINT UNSIGNED NULL AFTER reserved_until',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'fixed_client_case_id'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN fixed_client_case_id BIGINT UNSIGNED NULL AFTER reserved_by_case_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND COLUMN_NAME = 'view_count'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_properties ADD COLUMN view_count BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER fixed_client_case_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND INDEX_NAME = 'idx_tn_properties_inventory'
);
SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE tn_properties ADD KEY idx_tn_properties_inventory (visibility, sale_priority, status, updated_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND CONSTRAINT_NAME = 'fk_tn_properties_reserved_case'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE tn_properties ADD CONSTRAINT fk_tn_properties_reserved_case FOREIGN KEY (reserved_by_case_id) REFERENCES tn_client_cases (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_properties'
      AND CONSTRAINT_NAME = 'fk_tn_properties_fixed_case'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE tn_properties ADD CONSTRAINT fk_tn_properties_fixed_case FOREIGN KEY (fixed_client_case_id) REFERENCES tn_client_cases (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'utm_source'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN utm_source VARCHAR(120) NULL AFTER source_page',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'utm_medium'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN utm_medium VARCHAR(120) NULL AFTER utm_source',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'utm_campaign'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN utm_campaign VARCHAR(160) NULL AFTER utm_medium',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'utm_content'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN utm_content VARCHAR(160) NULL AFTER utm_campaign',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'utm_term'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN utm_term VARCHAR(160) NULL AFTER utm_content',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tn_analytics_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type ENUM('property_view', 'phone_click', 'telegram_click', 'viber_click', 'presentation_request', 'lead_submit', 'property_submit') NOT NULL,
    entity_type ENUM('property', 'lead', 'submission', 'client_case', 'system') NOT NULL DEFAULT 'system',
    entity_id BIGINT UNSIGNED NULL,
    property_id BIGINT UNSIGNED NULL,
    lead_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    source_page VARCHAR(255) NULL,
    utm_source VARCHAR(120) NULL,
    utm_medium VARCHAR(120) NULL,
    utm_campaign VARCHAR(160) NULL,
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_analytics_event_created (event_type, created_at),
    KEY idx_tn_analytics_property (property_id, created_at),
    KEY idx_tn_analytics_lead (lead_id, created_at),
    CONSTRAINT fk_tn_analytics_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_analytics_lead FOREIGN KEY (lead_id) REFERENCES tn_leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_analytics_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260718_000013_release06_p0_alignment');
