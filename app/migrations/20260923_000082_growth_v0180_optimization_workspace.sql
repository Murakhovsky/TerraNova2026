UPDATE cos_module_installations
SET installed_version='0.18.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.17.0'
  AND schema_version='0.17.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000082_growth_v0180_optimization_workspace');
