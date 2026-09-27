UPDATE cos_module_installations
SET installed_version='0.23.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.22.0'
  AND schema_version='0.22.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000087_growth_v0230_engagement_execution_workspace');
