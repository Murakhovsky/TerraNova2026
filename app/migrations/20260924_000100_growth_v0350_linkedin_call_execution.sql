UPDATE cos_module_installations
SET installed_version='0.35.0',
    schema_version='0.31.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.34.0'
  AND schema_version='0.31.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000100_growth_v0350_linkedin_call_execution');
