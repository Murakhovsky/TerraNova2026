CREATE TABLE IF NOT EXISTS cos_rules (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    code VARCHAR(160) NOT NULL,
    name VARCHAR(220) NOT NULL,
    trigger_type VARCHAR(160) NOT NULL,
    conditions JSON NOT NULL,
    effect JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    status ENUM('DRAFT', 'ACTIVE', 'DISABLED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_by_type ENUM('USER', 'AGENT', 'SYSTEM') NOT NULL DEFAULT 'USER',
    created_by_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_rules_org_code_version (organization_id, code, version),
    KEY idx_cos_rules_trigger (organization_id, trigger_type, status, priority),
    KEY idx_cos_rules_validity (organization_id, status, valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_rule_evaluations (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    rule_id VARCHAR(40) NOT NULL,
    event_id VARCHAR(40) NOT NULL,
    matched TINYINT(1) NOT NULL,
    context_snapshot JSON NULL,
    evaluation_details JSON NULL,
    correlation_id VARCHAR(40) NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_rule_evaluation (rule_id, event_id),
    KEY idx_cos_rule_evaluations_org_time (organization_id, evaluated_at),
    KEY idx_cos_rule_evaluations_event (event_id),
    CONSTRAINT fk_cos_rule_evaluations_rule FOREIGN KEY (rule_id) REFERENCES cos_rules (id),
    CONSTRAINT fk_cos_rule_evaluations_event FOREIGN KEY (event_id) REFERENCES cos_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_decisions (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    type VARCHAR(160) NOT NULL,
    source_type ENUM('RULE', 'AGENT', 'USER', 'SYSTEM', 'INTEGRATION') NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    subject_type VARCHAR(100) NULL,
    subject_id VARCHAR(100) NULL,
    decision VARCHAR(160) NOT NULL,
    reason TEXT NULL,
    confidence DECIMAL(5, 4) NULL,
    evidence JSON NULL,
    context_reference JSON NULL,
    correlation_id VARCHAR(40) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_decisions_org_subject (organization_id, subject_type, subject_id, created_at),
    KEY idx_cos_decisions_org_type (organization_id, type, created_at),
    KEY idx_cos_decisions_correlation (organization_id, correlation_id),
    CONSTRAINT chk_cos_decisions_confidence CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000009_cos_decisioning');
