-- Growth V0.11 adds Web Workspace/runtime composition without changing Growth-owned schema.
UPDATE cos_module_installations
SET installed_version='0.11.0',
    schema_version='0.8.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.10.0'
  AND schema_version='0.8.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000075_growth_v0110_workspace');
