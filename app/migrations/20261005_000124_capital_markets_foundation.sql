CREATE TABLE IF NOT EXISTS tn_capital_market_instruments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    symbol VARCHAR(64) NOT NULL,
    canonical_symbol VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    family VARCHAR(48) NOT NULL,
    status VARCHAR(24) NOT NULL,
    currency VARCHAR(3) NULL,
    quote_asset VARCHAR(20) NULL,
    issuer_reference VARCHAR(190) NULL,
    jurisdiction VARCHAR(190) NULL,
    primary_venue_reference VARCHAR(190) NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_instrument_id (organization_id,instrument_id),
    UNIQUE KEY uq_cm_instrument_canonical_symbol (organization_id,canonical_symbol),
    KEY ix_cm_instrument_family_status (organization_id,family,status),
    KEY ix_cm_instrument_symbol (organization_id,symbol),
    CONSTRAINT chk_cm_instrument_status CHECK (status IN ('DRAFT','ACTIVE','SUSPENDED','DELISTED','UNKNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_instrument_identifiers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    identifier_type VARCHAR(40) NOT NULL,
    identifier_source VARCHAR(120) NOT NULL DEFAULT '',
    identifier_value VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_identifier (organization_id,identifier_type,identifier_source,identifier_value),
    KEY ix_cm_identifier_instrument (organization_id,instrument_id),
    CONSTRAINT fk_cm_identifier_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_relationships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    relationship_id VARCHAR(190) NOT NULL,
    source_instrument_id VARCHAR(190) NOT NULL,
    target_instrument_id VARCHAR(190) NOT NULL,
    relationship_type VARCHAR(48) NOT NULL,
    strength VARCHAR(24) NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    effective_to DATETIME(6) NULL,
    status VARCHAR(24) NOT NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_relationship_id (organization_id,relationship_id),
    UNIQUE KEY uq_cm_relationship_edge (organization_id,source_instrument_id,relationship_type,target_instrument_id),
    KEY ix_cm_relationship_source (organization_id,source_instrument_id,status),
    KEY ix_cm_relationship_target (organization_id,target_instrument_id,status),
    CONSTRAINT fk_cm_relationship_source FOREIGN KEY (organization_id,source_instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cm_relationship_target FOREIGN KEY (organization_id,target_instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_cm_relationship_status CHECK (status IN ('ACTIVE','INACTIVE')),
    CONSTRAINT chk_cm_relationship_distinct CHECK (source_instrument_id <> target_instrument_id),
    CONSTRAINT chk_cm_relationship_window CHECK (effective_to IS NULL OR effective_to > effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_pairs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    pair_id VARCHAR(190) NOT NULL,
    instrument_a_id VARCHAR(190) NOT NULL,
    instrument_b_id VARCHAR(190) NOT NULL,
    relationship_id VARCHAR(190) NULL,
    purpose VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_pair_id (organization_id,pair_id),
    KEY ix_cm_pair_members (organization_id,instrument_a_id,instrument_b_id,status),
    CONSTRAINT fk_cm_pair_a FOREIGN KEY (organization_id,instrument_a_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cm_pair_b FOREIGN KEY (organization_id,instrument_b_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cm_pair_relationship FOREIGN KEY (organization_id,relationship_id)
        REFERENCES tn_capital_market_relationships (organization_id,relationship_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_cm_pair_distinct CHECK (instrument_a_id <> instrument_b_id),
    CONSTRAINT chk_cm_pair_status CHECK (status IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_venues (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    code VARCHAR(40) NOT NULL,
    venue_type VARCHAR(48) NOT NULL,
    status VARCHAR(24) NOT NULL,
    jurisdiction VARCHAR(190) NULL,
    timezone VARCHAR(80) NOT NULL,
    base_url_reference VARCHAR(190) NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_venue_id (organization_id,venue_id),
    UNIQUE KEY uq_cm_venue_code (organization_id,code),
    KEY ix_cm_venue_type_status (organization_id,venue_type,status),
    CONSTRAINT chk_cm_venue_status CHECK (status IN ('ACTIVE','SUSPENDED','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_venue_capabilities (
    organization_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    capability VARCHAR(48) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,venue_id,capability),
    CONSTRAINT fk_cm_venue_capability_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_venue_instruments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    venue_symbol VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    price_precision TINYINT UNSIGNED NOT NULL,
    quantity_precision TINYINT UNSIGNED NOT NULL,
    minimum_quantity DECIMAL(38,18) NULL,
    minimum_notional DECIMAL(38,18) NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_venue_instrument (organization_id,venue_id,instrument_id),
    UNIQUE KEY uq_cm_venue_symbol (organization_id,venue_id,venue_symbol),
    KEY ix_cm_instrument_venues (organization_id,instrument_id,status),
    CONSTRAINT fk_cm_venue_instrument_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cm_venue_instrument_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_cm_venue_instrument_status CHECK (status IN ('ACTIVE','SUSPENDED','DELISTED')),
    CONSTRAINT chk_cm_venue_price_precision CHECK (price_precision <= 30),
    CONSTRAINT chk_cm_venue_quantity_precision CHECK (quantity_precision <= 30),
    CONSTRAINT chk_cm_venue_min_quantity CHECK (minimum_quantity IS NULL OR minimum_quantity >= 0),
    CONSTRAINT chk_cm_venue_min_notional CHECK (minimum_notional IS NULL OR minimum_notional >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capital_market_user_capabilities (
    organization_id VARCHAR(190) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(160) NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    granted_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,user_id,capability),
    KEY ix_cm_user_capability (organization_id,capability,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.enabled','Capital Markets bounded context master switch',1,100,'cm-foundation-domain'),
    ('capital_markets.instrument_registry.enabled','Capital Markets instrument registry',1,100,'cm-foundation-instruments'),
    ('capital_markets.relationships.enabled','Capital Markets economic relationship graph',1,100,'cm-foundation-relationships'),
    ('capital_markets.venues.enabled','Capital Markets venue registry',1,100,'cm-foundation-venues'),
    ('capital_markets.paper_trading.enabled','Future Capital Markets paper trading runtime',0,0,'cm-paper-disabled'),
    ('capital_markets.live_trading.enabled','Future Capital Markets live trading runtime',0,0,'cm-live-disabled'),
    ('capital_markets.auto_execution.enabled','Future Capital Markets autonomous execution runtime',0,0,'cm-auto-execution-disabled');

INSERT IGNORE INTO capital_market_user_capabilities
    (organization_id,user_id,capability,status,granted_by)
SELECT u.organization_id,u.id,c.capability,'ACTIVE','cm-foundation-install'
FROM tn_users u
CROSS JOIN (
    SELECT 'capital_markets.view' capability UNION ALL
    SELECT 'capital_markets.manage' UNION ALL
    SELECT 'capital_markets.instrument.view' UNION ALL
    SELECT 'capital_markets.instrument.manage' UNION ALL
    SELECT 'capital_markets.relationship.view' UNION ALL
    SELECT 'capital_markets.relationship.manage' UNION ALL
    SELECT 'capital_markets.venue.view' UNION ALL
    SELECT 'capital_markets.venue.manage' UNION ALL
    SELECT 'capital_markets.audit.view'
) c
WHERE u.role='admin' AND u.status='active';

UPDATE cos_module_installations
SET installed_version='0.2.0',
    schema_version='0.2.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.1.0'
  AND schema_version='0.1.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261005_000124_capital_markets_foundation');
