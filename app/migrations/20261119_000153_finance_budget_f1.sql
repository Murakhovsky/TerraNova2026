-- Finance owns budget control. All totals are integer currency minor units; no Meta spend journal and no payment execution.
CREATE TABLE IF NOT EXISTS cos_finance_budget_envelopes (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    owner_id VARCHAR(190) NOT NULL,
    source_domain VARCHAR(40) NOT NULL,
    source_ref VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    currency CHAR(3) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    limit_minor BIGINT UNSIGNED NOT NULL,
    reserved_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    committed_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    KEY idx_finance_budget_scope (organization_id,source_domain,source_ref,state),
    CONSTRAINT chk_finance_budget_nonnegative CHECK (limit_minor > 0 AND reserved_minor + committed_minor <= limit_minor),
    CONSTRAINT chk_finance_budget_period CHECK (period_start <= period_end),
    CONSTRAINT chk_finance_budget_state CHECK (state IN ('DRAFT','ACTIVE','FROZEN','CLOSED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cos_finance_budget_reservations (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    envelope_id VARCHAR(40) NOT NULL,
    source_domain VARCHAR(40) NOT NULL,
    source_operation_key VARCHAR(191) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'RESERVED',
    actor_id VARCHAR(190) NOT NULL,
    action_id VARCHAR(190) NOT NULL,
    reason VARCHAR(250) NOT NULL,
    released_at DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    UNIQUE KEY uq_finance_budget_source_operation (organization_id,source_domain,source_operation_key),
    KEY idx_finance_budget_active_reservations (organization_id,envelope_id,state),
    CONSTRAINT fk_finance_budget_reservation_envelope FOREIGN KEY (organization_id,envelope_id)
        REFERENCES cos_finance_budget_envelopes(organization_id,id),
    CONSTRAINT chk_finance_budget_reservation_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_finance_budget_reservation_state CHECK (state IN ('RESERVED','RELEASED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cos_finance_budget_intents (
    organization_id VARCHAR(190) NOT NULL,
    operation VARCHAR(40) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    reference_id VARCHAR(40) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,operation,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cos_finance_budget_events (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    envelope_id VARCHAR(40) NOT NULL,
    reservation_id VARCHAR(40) DEFAULT NULL,
    event_type VARCHAR(48) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    actor_id VARCHAR(190) NOT NULL,
    action_id VARCHAR(190) NOT NULL,
    correlation_id VARCHAR(190) NOT NULL,
    details_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    KEY idx_finance_budget_events (organization_id,envelope_id,created_at),
    CONSTRAINT fk_finance_budget_event_envelope FOREIGN KEY (organization_id,envelope_id)
        REFERENCES cos_finance_budget_envelopes(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

