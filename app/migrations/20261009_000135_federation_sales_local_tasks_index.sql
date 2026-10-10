-- Supports trusted Sales local-task outcomes without a tenant-wide scan.
-- The metric reads only activity_type=task and the Run-relative time window.
ALTER TABLE tn_client_case_activities
    ADD KEY idx_tn_case_activities_org_type_created
        (organization_id, activity_type, created_at);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000135_federation_sales_local_tasks_index');
