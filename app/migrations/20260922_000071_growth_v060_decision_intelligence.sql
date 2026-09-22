CREATE TABLE IF NOT EXISTS tn_growth_qualification_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    policy_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    criteria_json JSON NOT NULL,
    activated_at DATETIME(6) NULL,
    archived_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_qualification_policy_revision (organization_id,policy_id,revision),
    KEY ix_growth_qualification_policy_status (organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_candidate_evaluations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    evaluation_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    policy_id VARCHAR(80) NOT NULL,
    policy_revision INT UNSIGNED NOT NULL,
    outcome VARCHAR(24) NOT NULL,
    rationale_json JSON NOT NULL,
    score_json JSON NOT NULL,
    failed_criteria_json JSON NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    model_version VARCHAR(120) NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_candidate_evaluation (organization_id,evaluation_id),
    KEY ix_growth_candidate_evaluation_candidate (organization_id,candidate_id,evaluated_at),
    KEY ix_growth_candidate_evaluation_policy (organization_id,policy_id,policy_revision,outcome,evaluated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.6.0',
    schema_version='0.6.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.5.0'
  AND schema_version='0.5.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000071_growth_v060_decision_intelligence');
