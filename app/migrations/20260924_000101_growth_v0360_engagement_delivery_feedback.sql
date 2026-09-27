CREATE TABLE IF NOT EXISTS tn_growth_engagement_delivery_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    observation_id VARCHAR(80) NOT NULL,
    source_event_id VARCHAR(191) NOT NULL,
    execution_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    action_id VARCHAR(80) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL,
    terminal TINYINT(1) NOT NULL DEFAULT 0,
    provider_reference VARCHAR(191) NULL,
    reason_code VARCHAR(120) NULL,
    reason_text VARCHAR(1000) NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_delivery_observation (organization_id,observation_id),
    UNIQUE KEY uq_growth_engagement_delivery_source_event (organization_id,source_event_id),
    KEY ix_growth_engagement_delivery_execution (organization_id,execution_id,occurred_at),
    KEY ix_growth_engagement_delivery_action (organization_id,action_id,occurred_at),
    KEY ix_growth_engagement_delivery_candidate (organization_id,candidate_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.36.0',
    schema_version='0.36.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.35.0'
  AND schema_version='0.31.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000101_growth_v0360_engagement_delivery_feedback');
