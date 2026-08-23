CREATE TABLE IF NOT EXISTS cos_jobs (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    type VARCHAR(160) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'DEAD') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    completed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    idempotency_key VARCHAR(191) NULL,
    correlation_id VARCHAR(40) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_jobs_idempotency (organization_id, idempotency_key),
    KEY idx_cos_jobs_claim (status, available_at, created_at),
    KEY idx_cos_jobs_lease (status, locked_at),
    KEY idx_cos_jobs_correlation (organization_id, correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260822_000015_cos_jobs');
