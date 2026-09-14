-- V0.8.6 closes EPIC 4 with indexes for current Director snapshot reads.
-- Every index is added conditionally so repeated deployment remains safe.

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tn_client_cases' AND INDEX_NAME='idx_sales_director_open_v086'
);
SET @sql := IF(
    @idx_exists=0,
    'CREATE INDEX idx_sales_director_open_v086 ON tn_client_cases (organization_id,status,pipeline_id,stage_id,expected_close_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_deal_stage_history' AND INDEX_NAME='idx_sales_stage_current_v086'
);
SET @sql := IF(
    @idx_exists=0,
    'CREATE INDEX idx_sales_stage_current_v086 ON sales_deal_stage_history (organization_id,deal_id,left_at,to_stage_id,history_quality,entered_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_deal_owner_history' AND INDEX_NAME='idx_sales_owner_current_v086'
);
SET @sql := IF(
    @idx_exists=0,
    'CREATE INDEX idx_sales_owner_current_v086 ON sales_deal_owner_history (organization_id,deal_id,unassigned_at,owner_user_id,history_quality,assigned_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260913_000047_sales_v086_hardening');
