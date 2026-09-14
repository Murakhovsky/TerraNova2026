CREATE TABLE IF NOT EXISTS diagnostic_packs (
    organization_id VARCHAR(40) NOT NULL,
    pack_id VARCHAR(100) NOT NULL,
    version INT UNSIGNED NOT NULL,
    name VARCHAR(220) NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    status ENUM('draft', 'published', 'retired') NOT NULL DEFAULT 'draft',
    methodology_json JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, pack_id, version),
    KEY idx_diagnostic_packs_target (organization_id, target_domain, status, version),
    CONSTRAINT fk_diagnostic_packs_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_sessions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    pack_id VARCHAR(100) NOT NULL,
    pack_version INT UNSIGNED NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    target_subject_type VARCHAR(100) NOT NULL,
    target_subject_id VARCHAR(160) NOT NULL,
    status ENUM('planned', 'in_progress', 'completed', 'cancelled') NOT NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, session_id),
    KEY idx_diagnostic_sessions_target (organization_id, target_domain, target_subject_type, target_subject_id, status),
    KEY idx_diagnostic_sessions_pack (organization_id, pack_id, pack_version),
    CONSTRAINT fk_diagnostic_sessions_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_diagnostic_sessions_pack FOREIGN KEY (organization_id, pack_id, pack_version)
        REFERENCES diagnostic_packs (organization_id, pack_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_evidence (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    evidence_id VARCHAR(100) NOT NULL,
    evidence_type ENUM('document', 'interview', 'system_data', 'observation', 'survey', 'external_source') NOT NULL,
    title VARCHAR(220) NOT NULL,
    source_reference VARCHAR(1000) NOT NULL,
    captured_at DATETIME(6) NOT NULL,
    metadata_json JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, session_id, evidence_id),
    KEY idx_diagnostic_evidence_type (organization_id, evidence_type, captured_at),
    CONSTRAINT fk_diagnostic_evidence_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_records (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    record_id VARCHAR(160) NOT NULL,
    record_type ENUM('fact', 'metric', 'assessment', 'finding', 'hypothesis', 'recommendation') NOT NULL,
    reference_code VARCHAR(160) NOT NULL,
    statement TEXT NOT NULL,
    value_json JSON NULL,
    unit VARCHAR(80) NULL,
    evidence_ids_json JSON NOT NULL,
    upstream_record_ids_json JSON NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, session_id, record_id),
    KEY idx_diagnostic_records_type (organization_id, session_id, record_type, reference_code),
    CONSTRAINT fk_diagnostic_records_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260830_000020_diagnostic_domain');
