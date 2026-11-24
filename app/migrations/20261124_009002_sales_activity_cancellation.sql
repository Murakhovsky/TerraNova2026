-- Cancellation is distinct from successful completion.
ALTER TABLE tn_client_case_activities ADD COLUMN cancelled_at DATETIME NULL, ADD COLUMN cancellation_reason VARCHAR(500) NULL;
ALTER TABLE tn_lead_activities ADD COLUMN cancelled_at DATETIME NULL, ADD COLUMN cancellation_reason VARCHAR(500) NULL;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261124_009002_sales_activity_cancellation');
