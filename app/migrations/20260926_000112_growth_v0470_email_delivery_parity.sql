UPDATE cos_module_installations
SET installed_version='0.47.0',
    schema_version='0.47.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.46.0'
  AND schema_version='0.46.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000112_growth_v0470_email_delivery_parity');
