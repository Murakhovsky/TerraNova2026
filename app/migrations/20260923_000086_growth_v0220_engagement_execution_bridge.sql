CREATE TABLE IF NOT EXISTS tn_growth_engagement_execution_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    execution_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    target_reference_type VARCHAR(80) NOT NULL,
    target_reference_id VARCHAR(191) NOT NULL,
    action_id VARCHAR(80) NOT NULL,
    action_type VARCHAR(120) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_execution_id (organization_id,execution_id),
    UNIQUE KEY uq_growth_engagement_execution_recommendation (organization_id,recommendation_id),
    UNIQUE KEY uq_growth_engagement_execution_action (organization_id,action_id),
    KEY ix_growth_engagement_execution_candidate (organization_id,candidate_id,created_at),
    KEY ix_growth_engagement_execution_target (organization_id,target_domain,target_reference_type,target_reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.22.0',
    schema_version='0.22.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.21.0'
  AND schema_version='0.21.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000086_growth_v0220_engagement_execution_bridge');
