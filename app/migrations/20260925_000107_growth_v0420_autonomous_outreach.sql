CREATE TABLE IF NOT EXISTS tn_growth_engagement_autonomy_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    min_confidence DECIMAL(5,4) NOT NULL,
    allowed_channels_json JSON NOT NULL,
    allowed_statuses_json JSON NOT NULL,
    max_actions_per_run INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_autonomy_profile (organization_id,profile_id),
    UNIQUE KEY uq_growth_engagement_autonomy_revision (organization_id,revision),
    KEY ix_growth_engagement_autonomy_enabled (enabled,organization_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_autonomy_payloads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    payload_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    staged_by BIGINT UNSIGNED NOT NULL,
    staged_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_autonomy_payload (organization_id,payload_id),
    UNIQUE KEY uq_growth_engagement_autonomy_payload_revision (organization_id,recommendation_id,revision),
    KEY ix_growth_engagement_autonomy_pending (organization_id,recommendation_id,revision,staged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.42.0',
    schema_version='0.42.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.41.0'
  AND schema_version='0.41.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260925_000107_growth_v0420_autonomous_outreach');
