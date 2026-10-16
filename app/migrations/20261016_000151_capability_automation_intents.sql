-- AI/CRON intake-only request envelopes, not Action/Run/Step stores.
-- Each signed nonce may create ONE reviewable intent; no machine write grant.
CREATE TABLE IF NOT EXISTS cos_capability_automation_intents (
    organization_id VARCHAR(190) NOT NULL,
    intent_id VARCHAR(64) NOT NULL,
    origin VARCHAR(16) NOT NULL,
    nonce VARCHAR(80) NOT NULL,
    request_digest CHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending_review',
    adopted_batch_id VARCHAR(64) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reviewed_at DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (organization_id,intent_id),
    UNIQUE KEY uq_capability_intent_nonce (organization_id,origin,nonce),
    KEY idx_capability_intent_review (organization_id,state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
