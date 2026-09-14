CREATE TABLE IF NOT EXISTS diagnostic_runtime_sessions (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    parent_session_id VARCHAR(100) NULL,
    mode VARCHAR(40) NOT NULL,
    state_json JSON NOT NULL,
    current_question_id VARCHAR(160) NULL,
    state_revision INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, session_id),
    KEY idx_diagnostic_runtime_parent (organization_id, parent_session_id),
    CONSTRAINT fk_diagnostic_runtime_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_reports (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    report_version INT UNSIGNED NOT NULL,
    report_json JSON NOT NULL,
    state_revision INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, report_version),
    KEY idx_diagnostic_reports_current (organization_id, session_id, created_at),
    CONSTRAINT fk_diagnostic_reports_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_runtime_recommendations (
    organization_id VARCHAR(40) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    recommendation_id VARCHAR(160) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(40) NOT NULL,
    action_id VARCHAR(64) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, session_id, recommendation_id),
    KEY idx_diagnostic_runtime_rec_action (organization_id, action_id),
    CONSTRAINT fk_diagnostic_runtime_rec_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_measurements (
    organization_id VARCHAR(40) NOT NULL,
    measurement_id VARCHAR(100) NOT NULL,
    session_id VARCHAR(100) NOT NULL,
    action_id VARCHAR(64) NOT NULL,
    metric_code VARCHAR(160) NOT NULL,
    metric_value DOUBLE NOT NULL,
    evidence_id VARCHAR(100) NULL,
    measured_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, measurement_id),
    KEY idx_diagnostic_measurements_session (organization_id, session_id, metric_code, measured_at),
    CONSTRAINT fk_diagnostic_measurements_session FOREIGN KEY (organization_id, session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostic_rediagnostic_schedules (
    organization_id VARCHAR(40) NOT NULL,
    schedule_id VARCHAR(100) NOT NULL,
    source_session_id VARCHAR(100) NOT NULL,
    followup_session_id VARCHAR(100) NULL,
    interval_days SMALLINT UNSIGNED NOT NULL,
    due_at DATETIME(6) NOT NULL,
    status ENUM('pending','started','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL,
    started_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id, schedule_id),
    UNIQUE KEY uq_diagnostic_rediagnostic (organization_id, source_session_id, interval_days),
    KEY idx_diagnostic_rediagnostic_due (organization_id, status, due_at),
    CONSTRAINT fk_diagnostic_rediagnostic_source FOREIGN KEY (organization_id, source_session_id)
        REFERENCES diagnostic_sessions (organization_id, session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000049_diagnostic_runtime_v060');
