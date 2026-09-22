-- Growth V0.12 adds Signal Operations Workspace over the existing V0.8 schema.
UPDATE cos_module_installations
SET installed_version='0.12.0',
    schema_version='0.8.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.11.0'
  AND schema_version='0.8.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000076_growth_v0120_signal_operations');
