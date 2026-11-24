CREATE TABLE IF NOT EXISTS marketing_attribution_capture_intents (
    organization_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    receipt_json JSON DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE marketing_attribution_evidence ADD KEY idx_mkt_attribution_cohort(organization_id,campaign_id,policy_version,observed_at,id);
