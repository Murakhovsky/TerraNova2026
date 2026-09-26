CREATE TABLE IF NOT EXISTS tn_growth_engagement_limit_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    daily_limit INT UNSIGNED NOT NULL,
    contact_cooldown_hours INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_limit_profile (organization_id,profile_id),
    UNIQUE KEY uq_growth_engagement_limit_revision (organization_id,revision),
    KEY ix_growth_engagement_limit_latest (organization_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.38.0',
    schema_version='0.38.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.37.0'
  AND schema_version='0.36.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000103_growth_v0380_tenant_outreach_limits');
