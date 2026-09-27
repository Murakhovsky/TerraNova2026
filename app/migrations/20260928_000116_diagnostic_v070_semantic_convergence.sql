-- Diagnostic V0.7.0: converge runtime persistence on the normative diagnostic model.
-- Existing diagnostic_records stays as a compatibility read surface during the cutover.
-- New semantic records are append-only and tenant/session scoped.

CREATE TABLE IF NOT EXISTS diagnostic_fact_revisions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    fact_id VARCHAR(160) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    value_json JSON NULL,
    value_type VARCHAR(40) NOT NULL,
    truth_level ENUM('OBSERVED','REPORTED','CALCULATED','DERIVED','INFERRED','ESTIMATED','ASSUMED') NOT NULL,
    confidence DECIMAL(6,5) NOT NULL,
    source VARCHAR(255) NOT NULL,
    evidence_ids_json JSON NOT NULL,
    supersedes_revision INT UNSIGNED NULL,
    reason VARCHAR(1000) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, fact_id, revision),
    KEY idx_diagnostic_fact_revision_latest (organization_id, session_id, fact_id, revision),
    CONSTRAINT fk_diagnostic_fact_revision_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_assessment_revisions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    assessment_id VARCHAR(160) NOT NULL,
    criterion_id VARCHAR(160) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    status ENUM('NOT_STARTED','INSUFFICIENT_DATA','ASSESSED','GOOD','WARNING','CRITICAL','NOT_APPLICABLE','CONTRADICTORY') NOT NULL,
    score DECIMAL(7,4) NULL,
    severity ENUM('NONE','INFO','LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'NONE',
    confidence DECIMAL(6,5) NOT NULL,
    coverage DECIMAL(6,5) NOT NULL,
    evidence_ids_json JSON NOT NULL,
    upstream_ids_json JSON NOT NULL,
    reason TEXT NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, assessment_id, revision),
    KEY idx_diagnostic_assessment_criterion (organization_id, session_id, criterion_id, revision),
    CONSTRAINT fk_diagnostic_assessment_revision_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_hypothesis_revisions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    hypothesis_id VARCHAR(160) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    status ENUM('UNVERIFIED','SUPPORTED','STRONGLY_SUPPORTED','CONFIRMED_ROOT_CAUSE','REJECTED') NOT NULL,
    statement TEXT NOT NULL,
    confidence DECIMAL(6,5) NOT NULL,
    supporting_evidence_json JSON NOT NULL,
    contradicting_evidence_json JSON NOT NULL,
    causal_path_json JSON NOT NULL,
    policy_id VARCHAR(160) NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, hypothesis_id, revision),
    CONSTRAINT fk_diagnostic_hypothesis_revision_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_recommendation_transitions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    recommendation_id VARCHAR(160) NOT NULL,
    transition_no INT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status ENUM('PROPOSED','ACCEPTED','PLANNED','IN_PROGRESS','IMPLEMENTED','MEASURED','SUCCESSFUL','FAILED','REJECTED') NOT NULL,
    actor_reference VARCHAR(255) NULL,
    rationale TEXT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, recommendation_id, transition_no),
    CONSTRAINT fk_diagnostic_recommendation_transition_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_state_snapshots (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    pack_id VARCHAR(100) NOT NULL,
    pack_version INT UNSIGNED NOT NULL,
    state_json JSON NOT NULL,
    input_ids_json JSON NOT NULL,
    computed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, revision),
    CONSTRAINT fk_diagnostic_state_snapshot_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260928_000116_diagnostic_v070_semantic_convergence');
