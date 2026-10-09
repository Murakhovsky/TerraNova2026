CREATE TABLE IF NOT EXISTS tn_capital_market_portfolio_valuation_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    snapshot_id VARCHAR(190) NOT NULL,
    portfolio_id VARCHAR(190) NOT NULL,
    valued_at DATETIME(6) NOT NULL,
    currency VARCHAR(32) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_nav_snapshot_identity (organization_id, snapshot_id),
    KEY idx_cm_nav_snapshot_window (organization_id, portfolio_id, valued_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
