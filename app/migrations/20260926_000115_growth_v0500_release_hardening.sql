ALTER TABLE tn_growth_market_discovery_runs
    ADD COLUMN lease_token CHAR(32) NULL AFTER error_summary,
    ADD COLUMN lease_expires_at DATETIME(6) NULL AFTER lease_token,
    ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_expires_at,
    ADD KEY ix_growth_market_run_lease (organization_id,status,lease_expires_at);

UPDATE cos_module_installations
SET installed_version='0.50.0',
    schema_version='0.50.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.49.0'
  AND schema_version='0.49.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000115_growth_v0500_release_hardening');
