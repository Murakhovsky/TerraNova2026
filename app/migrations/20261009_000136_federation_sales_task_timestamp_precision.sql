-- Federation / Sales CRM timestamp precision alignment.
-- MySQL TIMESTAMP stores absolute instants in UTC and converts at connection
-- boundaries; retaining the TIMESTAMP type prevents a destructive timezone shift.
-- Existing second-resolution rows retain their instant and gain .000000.
-- Existing timezone-ambiguous DATETIME due/completed fields are deliberately
-- NOT reinterpreted or shifted. Run timestamps already use UTC DATETIME(6).
-- Run under the migration runner with a deployment window for the table ALTER.
ALTER TABLE tn_client_case_activities
    MODIFY COLUMN created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000136_federation_sales_task_timestamp_precision');
