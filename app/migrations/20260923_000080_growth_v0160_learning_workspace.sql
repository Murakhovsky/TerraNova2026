UPDATE cos_module_installations
SET installed_version='0.16.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.15.0'
  AND schema_version='0.15.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000080_growth_v0160_learning_workspace');
