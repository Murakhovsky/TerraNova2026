UPDATE cos_module_installations
SET installed_version='0.24.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.23.0'
  AND schema_version='0.22.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000089_growth_v0240_pre_handoff_execution');
