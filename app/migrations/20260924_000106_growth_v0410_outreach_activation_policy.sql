CREATE TABLE IF NOT EXISTS tn_growth_engagement_activation_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    email_mode VARCHAR(32) NOT NULL,
    linkedin_mode VARCHAR(32) NOT NULL,
    phone_mode VARCHAR(32) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by BIGINT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_activation_profile (organization_id,profile_id),
    UNIQUE KEY uq_growth_engagement_activation_revision (organization_id,revision),
    KEY idx_growth_engagement_activation_latest (organization_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.41.0',
    schema_version='0.41.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.40.0'
  AND schema_version='0.40.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000106_growth_v0410_outreach_activation_policy');
