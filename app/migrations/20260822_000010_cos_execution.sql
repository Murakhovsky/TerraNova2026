CREATE TABLE IF NOT EXISTS cos_actions (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    type VARCHAR(160) NOT NULL,
    target_type VARCHAR(100) NULL,
    target_id VARCHAR(100) NULL,
    parameters JSON NOT NULL,
    source_type ENUM('RULE', 'DECISION', 'AGENT', 'USER', 'INTEGRATION', 'SYSTEM') NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    status ENUM('PROPOSED', 'PENDING_APPROVAL', 'QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'REJECTED') NOT NULL DEFAULT 'PROPOSED',
    execution_mode ENUM('AUTO', 'APPROVAL_REQUIRED', 'MANUAL') NOT NULL DEFAULT 'MANUAL',
    risk_level ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL DEFAULT 'LOW',
    idempotency_key VARCHAR(191) NULL,
    correlation_id VARCHAR(40) NOT NULL,
    available_at DATETIME(6) NULL,
    started_at DATETIME(6) NULL,
    executed_at DATETIME(6) NULL,
    failed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_actions_idempotency (organization_id, idempotency_key),
    KEY idx_cos_actions_queue (status, available_at, created_at),
    KEY idx_cos_actions_target (organization_id, target_type, target_id, created_at),
    KEY idx_cos_actions_correlation (organization_id, correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_action_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action_id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    worker_id VARCHAR(100) NULL,
    status ENUM('RUNNING', 'COMPLETED', 'FAILED') NOT NULL,
    result JSON NULL,
    error TEXT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_action_attempt (action_id, attempt),
    KEY idx_cos_action_attempts_org_status (organization_id, status, started_at),
    CONSTRAINT fk_cos_action_attempts_action FOREIGN KEY (action_id) REFERENCES cos_actions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_policies (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    code VARCHAR(160) NOT NULL,
    name VARCHAR(220) NOT NULL,
    action_type VARCHAR(160) NOT NULL,
    conditions JSON NOT NULL,
    decision ENUM('AUTO', 'APPROVAL_REQUIRED', 'DENIED') NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('DRAFT', 'ACTIVE', 'DISABLED', 'ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_policies_org_code_version (organization_id, code, version),
    KEY idx_cos_policies_action (organization_id, action_type, status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_policy_evaluations (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    action_id VARCHAR(40) NOT NULL,
    policy_id VARCHAR(40) NULL,
    decision ENUM('AUTO', 'APPROVAL_REQUIRED', 'DENIED') NOT NULL,
    reason TEXT NULL,
    context_snapshot JSON NULL,
    correlation_id VARCHAR(40) NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_policy_evaluations_action (action_id, evaluated_at),
    KEY idx_cos_policy_evaluations_org (organization_id, decision, evaluated_at),
    CONSTRAINT fk_cos_policy_evaluations_action FOREIGN KEY (action_id) REFERENCES cos_actions (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_policy_evaluations_policy FOREIGN KEY (policy_id) REFERENCES cos_policies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_approvals (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    action_id VARCHAR(40) NOT NULL,
    status ENUM('PENDING', 'APPROVED', 'REJECTED', 'EXPIRED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    approver_type ENUM('USER', 'ROLE') NOT NULL,
    approver_id VARCHAR(100) NOT NULL,
    requested_by_type ENUM('USER', 'AGENT', 'WORKER', 'INTEGRATION', 'SYSTEM') NOT NULL,
    requested_by_id VARCHAR(100) NOT NULL,
    decided_by_type ENUM('USER', 'SYSTEM') NULL,
    decided_by_id VARCHAR(100) NULL,
    reason TEXT NULL,
    decision_note TEXT NULL,
    expires_at DATETIME(6) NULL,
    decided_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_cos_approvals_inbox (organization_id, approver_type, approver_id, status, created_at),
    KEY idx_cos_approvals_action (action_id),
    KEY idx_cos_approvals_expiry (status, expires_at),
    CONSTRAINT fk_cos_approvals_action FOREIGN KEY (action_id) REFERENCES cos_actions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000010_cos_execution');
