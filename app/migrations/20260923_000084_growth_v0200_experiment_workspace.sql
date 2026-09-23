UPDATE cos_module_installations
SET installed_version='0.20.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.19.0'
  AND schema_version='0.19.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000084_growth_v0200_experiment_workspace');
