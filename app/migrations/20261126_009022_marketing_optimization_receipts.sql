-- Immutable review/learning receipts from independently approved canonical Marketing Actions.
CREATE TABLE IF NOT EXISTS marketing_optimization_receipts (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    kind VARCHAR(24) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    action_id CHAR(32) NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    receipt_hash CHAR(64) NOT NULL,
    receipt_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,id),
    UNIQUE KEY uq_mkt_opt_key (organization_id,idempotency_key),
    UNIQUE KEY uq_mkt_opt_action (organization_id,action_id),
    UNIQUE KEY uq_mkt_opt_evidence (organization_id,kind,evidence_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
