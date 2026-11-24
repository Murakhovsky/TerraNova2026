-- Provider operation receipts, never provider spend or Finance accounting records.
CREATE TABLE IF NOT EXISTS marketing_paid_operation_journal (
    organization_id VARCHAR(190) NOT NULL,
    plan_id VARCHAR(40) NOT NULL,
    operation VARCHAR(40) NOT NULL,
    action_id VARCHAR(190) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'STARTED',
    provider_id VARCHAR(40) DEFAULT NULL,
    receipt_json JSON DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (organization_id,plan_id,operation),
    KEY idx_mkt_paid_operation_action (organization_id,action_id),
    CONSTRAINT fk_mkt_paid_operation_plan FOREIGN KEY (organization_id,plan_id)
        REFERENCES marketing_paid_launch_plans(organization_id,id),
    CONSTRAINT chk_mkt_paid_operation_state CHECK (state IN ('STARTED','CONFIRMED','UNCERTAIN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
