SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'request_intent'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN request_intent ENUM("general_contact", "presentation", "viewing", "similar_search") NOT NULL DEFAULT "general_contact" AFTER deal_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND INDEX_NAME = 'idx_tn_leads_intent_status'
);

SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE tn_leads ADD KEY idx_tn_leads_intent_status (request_intent, status, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
