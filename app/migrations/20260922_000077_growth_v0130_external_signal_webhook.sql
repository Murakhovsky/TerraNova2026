UPDATE cos_module_installations
SET installed_version='0.13.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.12.0'
  AND schema_version='0.8.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000077_growth_v0130_external_signal_webhook');
