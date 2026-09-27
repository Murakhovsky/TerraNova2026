-- Growth V0.10 is a code/API release over the existing V0.8 schema.
-- Keep schema_version at 0.8.0 while advancing installed_version so
-- ActiveModuleResolver::isCurrent() remains true for already-installed tenants.

UPDATE cos_module_installations
SET installed_version='0.10.0',
    schema_version='0.8.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version IN ('0.8.0','0.9.0')
  AND schema_version='0.8.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000074_growth_v0100_api_surface');
