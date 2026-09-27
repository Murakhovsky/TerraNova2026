UPDATE cos_module_installations
SET installed_version='0.31.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.30.0'
  AND schema_version='0.28.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000096_growth_v0310_polling_operations_workspace');
