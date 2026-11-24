-- Admission receipts are Marketing-owned advice, not a second Action executor or Finance ledger.
CREATE TABLE IF NOT EXISTS marketing_analyst_admission_counters (
    organization_id VARCHAR(190) NOT NULL,
    quota_day DATE NOT NULL,
    admitted_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_analyst_runs (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    requested_by VARCHAR(190) NOT NULL,
    profile_ref VARCHAR(190) NOT NULL,
    cost_control_ref VARCHAR(190) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    context_hash CHAR(64) NOT NULL,
    source_pins_json JSON NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'STARTED',
    advice_json JSON DEFAULT NULL,
    failure_code VARCHAR(40) DEFAULT NULL,
    quota_day DATE NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (organization_id,id),
    UNIQUE KEY uq_mkt_analyst_run_key (organization_id,idempotency_key),
    KEY idx_mkt_analyst_run_state (organization_id,state,created_at),
    CONSTRAINT fk_mkt_analyst_run_campaign FOREIGN KEY (organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id),
    CONSTRAINT chk_mkt_analyst_run_state CHECK (state IN ('STARTED','SUCCEEDED','UNCERTAIN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
