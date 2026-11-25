-- Immutable funding candidate only. No spend, payment, approval or provider write.
CREATE TABLE IF NOT EXISTS marketing_paid_launch_plans (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    plan_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    UNIQUE KEY uq_mkt_launch_key (organization_id,idempotency_key),
    KEY idx_mkt_launch_campaign (organization_id,campaign_id,created_at),
    CONSTRAINT fk_mkt_launch_campaign FOREIGN KEY (organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
