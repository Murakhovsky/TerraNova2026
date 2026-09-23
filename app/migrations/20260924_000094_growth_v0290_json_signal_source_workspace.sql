UPDATE cos_module_installations
SET installed_version='0.29.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.28.0'
  AND schema_version='0.28.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000094_growth_v0290_json_signal_source_workspace');
