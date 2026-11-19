-- Marketing M0 canonical records. No token or Finance accounting columns.
CREATE TABLE IF NOT EXISTS marketing_campaigns (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    owner_id VARCHAR(190) NOT NULL,
    name VARCHAR(180) NOT NULL,
    business_objective VARCHAR(250) NOT NULL,
    brief TEXT NOT NULL,
    product_refs_json JSON NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    KEY idx_marketing_campaign_status(organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_campaign_revisions (
    organization_id VARCHAR(190) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    snapshot_json JSON NOT NULL,
    reason VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,campaign_id,revision),
    CONSTRAINT fk_mkt_revision_campaign FOREIGN KEY(organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_campaign_intents (
    organization_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,idempotency_key),
    CONSTRAINT fk_mkt_intent_campaign FOREIGN KEY(organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_audiences (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    name VARCHAR(190) NOT NULL,
    target_spec_json JSON NOT NULL,
    consent_policy_ref VARCHAR(190) DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_creative_briefs (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    brief_json JSON NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    CONSTRAINT fk_mkt_creative_campaign FOREIGN KEY(organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_creative_revisions (
    organization_id VARCHAR(190) NOT NULL,
    creative_id VARCHAR(40) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    content_json JSON NOT NULL,
    approval_ref VARCHAR(190) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,creative_id,revision),
    CONSTRAINT fk_mkt_creative_revision FOREIGN KEY(organization_id,creative_id)
        REFERENCES marketing_creative_briefs(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_accounts (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    provider VARCHAR(30) NOT NULL,
    business_id VARCHAR(100) DEFAULT NULL,
    ad_account_id VARCHAR(100) NOT NULL,
    currency CHAR(3) NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    credential_ref VARCHAR(190) NOT NULL,
    connection_status VARCHAR(30) NOT NULL DEFAULT 'CONNECTING',
    capability_scope_json JSON NOT NULL,
    last_verified_at DATETIME(6) DEFAULT NULL,
    last_synced_at DATETIME(6) DEFAULT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_provider_account_global(provider,ad_account_id),
    KEY idx_mkt_account_status(organization_id,connection_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_campaigns (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    marketing_campaign_id VARCHAR(40) DEFAULT NULL,
    account_id VARCHAR(40) NOT NULL,
    provider_campaign_id VARCHAR(100) NOT NULL,
    name VARCHAR(190) NOT NULL,
    objective VARCHAR(100) NOT NULL,
    delivery_status VARCHAR(50) NOT NULL,
    management_mode VARCHAR(30) NOT NULL DEFAULT 'EXTERNALLY_MANAGED',
    source_hash CHAR(64) NOT NULL,
    source_updated_at DATETIME(6) DEFAULT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_paid_campaign(organization_id,account_id,provider_campaign_id),
    CONSTRAINT fk_mkt_paid_campaign_account FOREIGN KEY(organization_id,account_id)
        REFERENCES marketing_paid_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_adsets (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    paid_campaign_id VARCHAR(40) NOT NULL,
    provider_adset_id VARCHAR(100) NOT NULL,
    name VARCHAR(190) NOT NULL,
    delivery_status VARCHAR(50) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_paid_adset(organization_id,paid_campaign_id,provider_adset_id),
    CONSTRAINT fk_mkt_paid_adset_campaign FOREIGN KEY(organization_id,paid_campaign_id)
        REFERENCES marketing_paid_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_ads (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    paid_adset_id VARCHAR(40) NOT NULL,
    provider_ad_id VARCHAR(100) NOT NULL,
    creative_ref VARCHAR(100) DEFAULT NULL,
    name VARCHAR(190) NOT NULL,
    delivery_status VARCHAR(50) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_paid_ad(organization_id,paid_adset_id,provider_ad_id),
    CONSTRAINT fk_mkt_paid_ad_adset FOREIGN KEY(organization_id,paid_adset_id)
        REFERENCES marketing_paid_adsets(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_insight_snapshots (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    account_id VARCHAR(40) NOT NULL,
    provider VARCHAR(30) NOT NULL,
    grain VARCHAR(20) NOT NULL,
    external_id VARCHAR(100) NOT NULL,
    window_start DATE NOT NULL,
    window_end DATE NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    currency CHAR(3) NOT NULL,
    attribution_hash CHAR(64) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    source_status VARCHAR(30) NOT NULL DEFAULT 'PROVISIONAL',
    spend_decimal DECIMAL(20,6) NOT NULL DEFAULT 0,
    metrics_json JSON NOT NULL,
    fetched_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_insight_scope(organization_id,account_id,grain,external_id,window_start,window_end,attribution_hash),
    KEY idx_mkt_insight_window(organization_id,account_id,grain,window_start,window_end),
    CONSTRAINT fk_mkt_insight_account FOREIGN KEY(organization_id,account_id)
        REFERENCES marketing_paid_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_sync_runs (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    account_id VARCHAR(40) NOT NULL,
    requested_by VARCHAR(190) NOT NULL,
    state VARCHAR(30) NOT NULL,
    cursor_json JSON DEFAULT NULL,
    fetched_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(100) DEFAULT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY(organization_id,id),
    KEY idx_mkt_sync_account(organization_id,account_id,started_at),
    CONSTRAINT fk_mkt_sync_account FOREIGN KEY(organization_id,account_id)
        REFERENCES marketing_paid_accounts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_paid_change_requests (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    campaign_revision INT UNSIGNED NOT NULL,
    operation VARCHAR(100) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    state VARCHAR(40) NOT NULL DEFAULT 'BLOCKED_FINANCE',
    approval_ref VARCHAR(190) DEFAULT NULL,
    finance_reservation_ref VARCHAR(190) DEFAULT NULL,
    provider_receipt_json JSON DEFAULT NULL,
    reason VARCHAR(300) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,id),
    KEY idx_mkt_change_state(organization_id,state),
    CONSTRAINT fk_mkt_change_campaign FOREIGN KEY(organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_attribution_evidence (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    source_domain VARCHAR(40) NOT NULL,
    source_event_id VARCHAR(190) NOT NULL,
    policy_version VARCHAR(40) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    evidence_json JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY(organization_id,id),
    UNIQUE KEY uq_mkt_attribution_event(organization_id,source_domain,source_event_id,policy_version),
    CONSTRAINT fk_mkt_attribution_campaign FOREIGN KEY(organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

