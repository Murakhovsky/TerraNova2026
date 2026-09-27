CREATE TABLE IF NOT EXISTS tn_growth_experiments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    experiment_id VARCHAR(80) NOT NULL,
    name VARCHAR(191) NOT NULL,
    hypothesis TEXT NOT NULL,
    dimension VARCHAR(40) NOT NULL,
    primary_outcome VARCHAR(80) NOT NULL,
    variants_json JSON NOT NULL,
    status VARCHAR(24) NOT NULL,
    started_at DATETIME(6) NULL,
    ended_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_experiment (organization_id,experiment_id),
    KEY ix_growth_experiment_status (organization_id,status,updated_at),
    KEY ix_growth_experiment_dimension (organization_id,dimension,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_experiment_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    assignment_id VARCHAR(80) NOT NULL,
    experiment_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    variant_key VARCHAR(64) NOT NULL,
    assignment_source VARCHAR(24) NOT NULL,
    context_snapshot_json JSON NOT NULL,
    assigned_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_experiment_assignment (organization_id,assignment_id),
    UNIQUE KEY uq_growth_experiment_candidate (organization_id,experiment_id,candidate_id),
    KEY ix_growth_experiment_variant (organization_id,experiment_id,variant_key,assigned_at),
    KEY ix_growth_experiment_candidate_lookup (organization_id,candidate_id,assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.19.0',
    schema_version='0.19.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.18.0'
  AND schema_version='0.17.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000083_growth_v0190_experiments_attribution');
