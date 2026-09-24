UPDATE cos_module_installations
SET installed_version='0.37.0',
    schema_version='0.36.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.36.0'
  AND schema_version='0.36.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000102_growth_v0370_outreach_guardrails');
