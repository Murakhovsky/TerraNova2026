CREATE TABLE IF NOT EXISTS tn_capital_market_nav_financial_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    portfolio_id VARCHAR(190) NOT NULL,
    evidence_id VARCHAR(190) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    source_document_sha256 CHAR(64) NOT NULL,
    effective_at DATETIME(6) NOT NULL,
    record_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_nav_financial_evidence (organization_id, portfolio_id, evidence_id),
    KEY idx_cm_nav_financial_evidence (organization_id, portfolio_id, kind, effective_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
