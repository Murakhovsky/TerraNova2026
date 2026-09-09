CREATE TABLE IF NOT EXISTS diagnostic_assessment_results (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    criterion_id VARCHAR(160) NOT NULL,
    score DECIMAL(8,4) NULL,
    coverage DECIMAL(7,6) NOT NULL,
    confidence DECIMAL(7,6) NOT NULL,
    applicable TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, session_id, criterion_id),
    KEY idx_diagnostic_assessment_results_session (organization_id, session_id, applicable),
    CONSTRAINT fk_diagnostic_assessment_results_session
        FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260909_000029_diagnostic_assessment_projection');
