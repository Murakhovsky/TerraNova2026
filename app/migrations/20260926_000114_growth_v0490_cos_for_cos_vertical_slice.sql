UPDATE cos_module_installations
SET installed_version='0.49.0',
    schema_version='0.49.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.48.0'
  AND schema_version='0.48.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000114_growth_v0490_cos_for_cos_vertical_slice');
