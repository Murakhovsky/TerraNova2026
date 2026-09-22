CREATE TABLE IF NOT EXISTS tn_growth_research_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    context_snapshot_json JSON NOT NULL,
    proposal_id VARCHAR(80) NULL,
    provider VARCHAR(120) NULL,
    model VARCHAR(191) NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cost_amount DECIMAL(18,8) NULL,
    cost_currency VARCHAR(16) NULL,
    error_summary VARCHAR(2000) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_research_run (organization_id,run_id),
    KEY ix_growth_research_candidate (organization_id,candidate_id,started_at),
    KEY ix_growth_research_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_research_proposals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    proposal_id VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    why_it_matters VARCHAR(2000) NOT NULL,
    problem_hypothesis VARCHAR(2000) NOT NULL,
    why_now VARCHAR(2000) NOT NULL,
    evidence_ids_json JSON NOT NULL,
    counter_evidence_ids_json JSON NOT NULL,
    assumptions_json JSON NOT NULL,
    unknowns_json JSON NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    provider VARCHAR(120) NOT NULL,
    model VARCHAR(191) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    proposed_at DATETIME(6) NOT NULL,
    accepted_at DATETIME(6) NULL,
    accepted_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_research_proposal (organization_id,proposal_id),
    UNIQUE KEY uq_growth_research_run_proposal (organization_id,run_id),
    KEY ix_growth_research_proposal_candidate (organization_id,candidate_id,proposed_at),
    KEY ix_growth_research_acceptance (organization_id,candidate_id,accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.7.0',
    schema_version='0.7.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.6.0'
  AND schema_version='0.6.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000072_growth_v070_research_intelligence');
