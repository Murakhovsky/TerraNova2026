-- Property V0.9.0: derived intelligence is stored separately from canonical Property facts.
-- AI/heuristic outputs are versioned, evidence-linked inferences and must never overwrite Asset/Inventory truth.

CREATE TABLE IF NOT EXISTS tn_property_intelligence_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    inference_id VARCHAR(80) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    inventory_id VARCHAR(80) NULL,
    provider VARCHAR(80) NOT NULL,
    model VARCHAR(160) NULL,
    method VARCHAR(80) NOT NULL,
    methodology_version VARCHAR(40) NOT NULL,
    evidence_hash CHAR(64) NOT NULL,
    facts_json JSON NOT NULL,
    market_signals_json JSON NULL,
    output_json JSON NOT NULL,
    estimated_market_value_low DECIMAL(18,2) NULL,
    estimated_market_value_high DECIMAL(18,2) NULL,
    value_currency CHAR(3) NULL,
    liquidity_score DECIMAL(5,2) NULL,
    demand_score DECIMAL(5,2) NULL,
    market_position VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
    price_anomaly_percent DECIMAL(8,3) NULL,
    inventory_risk VARCHAR(32) NULL,
    expected_dom_min SMALLINT UNSIGNED NULL,
    expected_dom_max SMALLINT UNSIGNED NULL,
    recommended_asking_price DECIMAL(18,2) NULL,
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    explanation TEXT NULL,
    correlation_id VARCHAR(80) NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cost_amount DECIMAL(14,6) NULL,
    cost_currency CHAR(3) NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until TIMESTAMP NULL,
    superseded_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_intelligence_identity (organization_id, inference_id),
    KEY idx_tn_property_intelligence_asset_time (organization_id, asset_id, generated_at),
    KEY idx_tn_property_intelligence_current (organization_id, asset_id, superseded_at, generated_at),
    KEY idx_tn_property_intelligence_market (organization_id, market_position, liquidity_score, demand_score),
    CONSTRAINT fk_tn_property_intelligence_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_intelligence_inventory
        FOREIGN KEY (organization_id, inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_intelligence_comparables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    inference_id VARCHAR(80) NOT NULL,
    comparable_asset_id VARCHAR(80) NOT NULL,
    comparable_inventory_id VARCHAR(80) NULL,
    similarity_score DECIMAL(5,2) NOT NULL,
    selected_price DECIMAL(18,2) NULL,
    selected_currency CHAR(3) NULL,
    selected_area DECIMAL(14,3) NULL,
    selected_price_per_sqm DECIMAL(18,2) NULL,
    reason_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_intelligence_comparable (organization_id, inference_id, comparable_asset_id),
    KEY idx_tn_property_intelligence_comparable_asset (organization_id, comparable_asset_id),
    CONSTRAINT fk_tn_property_intelligence_comparable_snapshot
        FOREIGN KEY (organization_id, inference_id)
        REFERENCES tn_property_intelligence_snapshots (organization_id, inference_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_intelligence_comparable_asset
        FOREIGN KEY (organization_id, comparable_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_intelligence_comparable_inventory
        FOREIGN KEY (organization_id, comparable_inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000056_property_v090_intelligence');
