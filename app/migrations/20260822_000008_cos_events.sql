CREATE TABLE IF NOT EXISTS cos_events (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    type VARCHAR(160) NOT NULL,
    aggregate_type VARCHAR(100) NULL,
    aggregate_id VARCHAR(100) NULL,
    payload JSON NOT NULL,
    metadata JSON NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    correlation_id VARCHAR(40) NOT NULL,
    causation_id VARCHAR(40) NULL,
    actor_type ENUM('USER', 'AGENT', 'WORKER', 'INTEGRATION', 'SYSTEM') NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    recorded_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_cos_events_org_type_time (organization_id, type, occurred_at),
    KEY idx_cos_events_aggregate (organization_id, aggregate_type, aggregate_id, occurred_at),
    KEY idx_cos_events_correlation (organization_id, correlation_id),
    KEY idx_cos_events_causation (causation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_event_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    status ENUM('PENDING', 'PROCESSING', 'PUBLISHED', 'FAILED', 'DEAD') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    published_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_event_outbox_event (event_id),
    KEY idx_cos_event_outbox_dispatch (status, available_at, id),
    KEY idx_cos_event_outbox_org (organization_id, status, created_at),
    CONSTRAINT fk_cos_event_outbox_event FOREIGN KEY (event_id) REFERENCES cos_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_event_consumptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    consumer_name VARCHAR(160) NOT NULL,
    status ENUM('PROCESSING', 'COMPLETED', 'FAILED', 'DEAD') NOT NULL DEFAULT 'PROCESSING',
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    started_at DATETIME(6) NOT NULL,
    processed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_event_consumption (event_id, consumer_name),
    KEY idx_cos_event_consumptions_org_status (organization_id, status, updated_at),
    CONSTRAINT fk_cos_event_consumptions_event FOREIGN KEY (event_id) REFERENCES cos_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000008_cos_events');
