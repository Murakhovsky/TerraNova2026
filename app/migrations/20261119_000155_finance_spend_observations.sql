-- Finance F1 source-reported evidence only. Not a payment, Expense or reserved amount.
CREATE TABLE IF NOT EXISTS cos_finance_spend_observations (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    provider VARCHAR(16) NOT NULL DEFAULT 'meta',
    source_domain VARCHAR(32) NOT NULL DEFAULT 'marketing',
    ad_account_ref VARCHAR(40) NOT NULL,
    source_snapshot_ref VARCHAR(40) NOT NULL,
    grain VARCHAR(20) NOT NULL,
    external_id VARCHAR(100) NOT NULL,
    window_start DATE NOT NULL,
    window_end DATE NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    currency CHAR(3) NOT NULL,
    attribution_hash CHAR(64) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_revision INT UNSIGNED NOT NULL,
    source_status VARCHAR(32) NOT NULL,
    reported_spend_decimal DECIMAL(20,6) NOT NULL,
    evidence_verification VARCHAR(32) NOT NULL DEFAULT 'UNVERIFIED_SOURCE',
    first_observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_finance_spend_source_ref(organization_id,source_domain,source_snapshot_ref),
    UNIQUE KEY uq_finance_spend_scope(organization_id,provider,ad_account_ref,grain,external_id,window_start,window_end,attribution_hash),
    KEY idx_finance_spend_window(organization_id,ad_account_ref,window_start,window_end,currency),
    CONSTRAINT chk_finance_spend_grain CHECK (grain='campaign'),
    CONSTRAINT chk_finance_spend_status CHECK (source_status IN ('PROVISIONAL','REVISED','FINALIZED_BY_SOURCE')),
    CONSTRAINT chk_finance_spend_verification CHECK (evidence_verification='UNVERIFIED_SOURCE'),
    CONSTRAINT chk_finance_spend_amount CHECK (reported_spend_decimal>=0),
    CONSTRAINT chk_finance_spend_dates CHECK (window_start<=window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cos_finance_spend_evidence_events (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    observation_id VARCHAR(40) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    source_revision INT UNSIGNED NOT NULL,
    source_hash CHAR(64) NOT NULL,
    reported_spend_decimal DECIMAL(20,6) NOT NULL,
    correlation_id VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_finance_spend_event_revision(organization_id,observation_id,source_revision),
    CONSTRAINT fk_finance_spend_event_observation FOREIGN KEY(organization_id,observation_id)
        REFERENCES cos_finance_spend_observations(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

