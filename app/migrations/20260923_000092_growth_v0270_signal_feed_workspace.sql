UPDATE cos_module_installations
SET installed_version='0.27.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.26.0'
  AND schema_version='0.26.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000092_growth_v0270_signal_feed_workspace');
