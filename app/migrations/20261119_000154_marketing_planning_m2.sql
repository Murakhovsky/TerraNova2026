-- M2: planning-only creative/version and human-reviewed analyst recommendations.
-- Existing marketing_creative_briefs/marketing_creative_revisions remain Marketing-owned.
CREATE TABLE IF NOT EXISTS marketing_analyst_proposals (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    campaign_revision INT UNSIGNED NOT NULL,
    creative_id VARCHAR(40) NOT NULL,
    creative_revision INT UNSIGNED NOT NULL,
    submitted_by VARCHAR(190) NOT NULL,
    origin VARCHAR(30) NOT NULL DEFAULT 'HUMAN',
    recommendation TEXT NOT NULL,
    rationale TEXT NOT NULL,
    evidence_json JSON NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'PROPOSED',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    reviewed_by VARCHAR(190) DEFAULT NULL,
    review_reason VARCHAR(300) DEFAULT NULL,
    reviewed_at DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    KEY idx_mkt_analyst_campaign (organization_id,campaign_id,status,created_at),
    CONSTRAINT fk_mkt_analyst_campaign FOREIGN KEY (organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id),
    CONSTRAINT fk_mkt_analyst_creative FOREIGN KEY (organization_id,creative_id)
        REFERENCES marketing_creative_briefs(organization_id,id),
    CONSTRAINT chk_mkt_analyst_status CHECK (status IN ('PROPOSED','ACCEPTED','REJECTED')),
    CONSTRAINT chk_mkt_analyst_origin CHECK (origin IN ('HUMAN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_planning_intents (
    organization_id VARCHAR(190) NOT NULL,
    operation VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    record_id VARCHAR(40) NOT NULL,
    record_revision INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,operation,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

