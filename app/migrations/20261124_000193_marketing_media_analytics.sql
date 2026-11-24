-- M4: immutable complete read-only provider batch, separate from mutable latest M1 observations.
CREATE TABLE IF NOT EXISTS marketing_media_analytics_runs (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    account_id VARCHAR(40) NOT NULL,
    account_revision INT UNSIGNED NOT NULL,
    grain VARCHAR(20) NOT NULL,
    window_start DATE NOT NULL,
    window_end DATE NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    evidence_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_analytics_run_key(organization_id,idempotency_key),
    KEY idx_mkt_analytics_run_scope(organization_id,account_id,grain,window_start,window_end,created_at,id),
    CONSTRAINT fk_mkt_analytics_run_account FOREIGN KEY(organization_id,account_id)
        REFERENCES marketing_paid_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
