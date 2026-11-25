-- Internal planning, not external provider objects or Finance reservations.
CREATE TABLE IF NOT EXISTS marketing_audience_revisions (
    organization_id VARCHAR(190) NOT NULL,
    audience_id VARCHAR(40) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    content_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,audience_id,revision),
    CONSTRAINT fk_mkt_audience_version FOREIGN KEY (organization_id,audience_id)
        REFERENCES marketing_audiences(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_adset_drafts (
    organization_id VARCHAR(190) NOT NULL,
    id VARCHAR(40) NOT NULL,
    campaign_id VARCHAR(40) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,id),
    KEY idx_mkt_adset_draft_campaign (organization_id,campaign_id,updated_at),
    CONSTRAINT fk_mkt_adset_draft_campaign FOREIGN KEY (organization_id,campaign_id)
        REFERENCES marketing_campaigns(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_adset_revisions (
    organization_id VARCHAR(190) NOT NULL,
    adset_id VARCHAR(40) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    content_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,adset_id,revision),
    CONSTRAINT fk_mkt_adset_version FOREIGN KEY (organization_id,adset_id)
        REFERENCES marketing_adset_drafts(organization_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
