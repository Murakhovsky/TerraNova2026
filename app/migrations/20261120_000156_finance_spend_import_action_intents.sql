-- Finance-owned idempotency fence for approved Marketing spend evidence readback.
-- This does not represent an expense, provider charge, budget reservation or payment.
CREATE TABLE IF NOT EXISTS cos_finance_spend_import_action_intents (
    organization_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    ad_account_ref VARCHAR(40) NOT NULL,
    source_snapshot_ref VARCHAR(40) NOT NULL,
    expected_revision INT UNSIGNED NOT NULL,
    expected_source_hash CHAR(64) NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'CLAIMED',
    finance_observation_id VARCHAR(40) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id,idempotency_key),
    KEY idx_finance_source_action_scope (organization_id,ad_account_ref,source_snapshot_ref),
    CONSTRAINT chk_finance_source_action_state CHECK (state IN ('CLAIMED','SUCCEEDED')),
    CONSTRAINT chk_finance_source_action_revision CHECK (expected_revision>=1),
    CONSTRAINT fk_finance_source_action_observation FOREIGN KEY (organization_id,finance_observation_id)
        REFERENCES cos_finance_spend_observations (organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
