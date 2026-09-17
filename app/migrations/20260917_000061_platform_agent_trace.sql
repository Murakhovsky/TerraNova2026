CREATE TABLE IF NOT EXISTS cos_agent_trace_events (
    run_id VARCHAR(40) NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(40) NOT NULL,
    duration_ms INT UNSIGNED NULL,
    cost_amount DECIMAL(14, 6) NULL,
    cost_unit VARCHAR(16) NULL,
    error TEXT NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (run_id, sequence),
    KEY idx_cos_agent_trace_org_time (organization_id, occurred_at),
    KEY idx_cos_agent_trace_type (organization_id, event_type, occurred_at),
    CONSTRAINT fk_cos_agent_trace_run FOREIGN KEY (run_id)
        REFERENCES cos_agent_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260917_000061_platform_agent_trace');
