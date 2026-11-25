-- Immutable independent operator decision; no provider Action or financial approval.
CREATE TABLE IF NOT EXISTS marketing_analyst_run_decisions (
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(40) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    decision VARCHAR(30) NOT NULL,
    reason VARCHAR(300) NOT NULL,
    evidence_ref VARCHAR(190) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,run_id),
    UNIQUE KEY uq_mkt_analyst_decision_key (organization_id,idempotency_key),
    CONSTRAINT fk_mkt_analyst_decision_run FOREIGN KEY (organization_id,run_id)
        REFERENCES marketing_analyst_runs(organization_id,id),
    CONSTRAINT chk_mkt_analyst_decision CHECK (decision IN ('ACCEPT','REJECT','RECOVERED_UNCERTAIN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
