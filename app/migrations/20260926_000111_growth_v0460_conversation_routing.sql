CREATE TABLE IF NOT EXISTS tn_growth_conversation_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    route_id VARCHAR(80) NOT NULL,
    response_id VARCHAR(80) NOT NULL,
    classification_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    contact_id VARCHAR(80) NULL,
    route VARCHAR(40) NOT NULL,
    policy_version VARCHAR(120) NOT NULL,
    decision_reason VARCHAR(1000) NOT NULL,
    status VARCHAR(40) NOT NULL,
    target_reference_type VARCHAR(80) NULL,
    target_reference_id VARCHAR(191) NULL,
    error_summary VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_conversation_route (organization_id,route_id),
    UNIQUE KEY uq_growth_conversation_route_classification (organization_id,classification_id),
    UNIQUE KEY uq_growth_conversation_route_response (organization_id,response_id),
    KEY ix_growth_conversation_route_candidate (organization_id,candidate_id,created_at),
    KEY ix_growth_conversation_route_response (organization_id,response_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_suppressions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    contact_id VARCHAR(80) NOT NULL,
    source_response_id VARCHAR(80) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_suppression (organization_id,contact_id),
    KEY ix_growth_engagement_suppression_active (organization_id,active,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.46.0',
    schema_version='0.46.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.45.0'
  AND schema_version='0.45.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000111_growth_v0460_conversation_routing');
