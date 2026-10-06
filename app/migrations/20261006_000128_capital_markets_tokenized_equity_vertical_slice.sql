CREATE TABLE IF NOT EXISTS tn_capital_market_spread_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    candidate_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    status VARCHAR(32) NOT NULL,
    detected_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    gross_edge_bps DECIMAL(30,12) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_spread_candidate (organization_id,candidate_id),
    KEY ix_cm_spread_candidate_hypothesis (organization_id,hypothesis,detected_at),
    KEY ix_cm_spread_candidate_status (organization_id,status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_opportunities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    candidate_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    status VARCHAR(32) NOT NULL,
    expected_net_edge_bps DECIMAL(30,12) NOT NULL,
    expected_pnl DECIMAL(30,12) NOT NULL,
    required_capital DECIMAL(30,12) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_opportunity (organization_id,opportunity_id),
    KEY ix_cm_opportunity_status (organization_id,status,expires_at),
    KEY ix_cm_opportunity_hypothesis (organization_id,hypothesis,created_at),
    CONSTRAINT fk_cm_opportunity_candidate FOREIGN KEY (organization_id,candidate_id)
        REFERENCES tn_capital_market_spread_candidates (organization_id,candidate_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_risk_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    risk_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    decision VARCHAR(32) NOT NULL,
    risk_score TINYINT UNSIGNED NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_risk_assessment (organization_id,risk_id),
    KEY ix_cm_risk_opportunity (organization_id,opportunity_id,created_at),
    CONSTRAINT fk_cm_risk_opportunity FOREIGN KEY (organization_id,opportunity_id)
        REFERENCES tn_capital_market_opportunities (organization_id,opportunity_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_risk_score CHECK (risk_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_executions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    execution_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    realized_pnl DECIMAL(30,12) NOT NULL DEFAULT 0,
    edge_capture_ratio DECIMAL(30,12) NOT NULL DEFAULT 0,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_paper_execution (organization_id,execution_id),
    KEY ix_cm_paper_execution_opportunity (organization_id,opportunity_id,created_at),
    CONSTRAINT fk_cm_execution_opportunity FOREIGN KEY (organization_id,opportunity_id)
        REFERENCES tn_capital_market_opportunities (organization_id,opportunity_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_ledger_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    transaction_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_ledger_transaction (organization_id,transaction_id),
    UNIQUE KEY uq_cm_ledger_idempotency (organization_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_portfolios (
    organization_id VARCHAR(190) NOT NULL,
    currency VARCHAR(32) NOT NULL,
    initial_capital DECIMAL(30,12) NOT NULL,
    available_capital DECIMAL(30,12) NOT NULL,
    reserved_capital DECIMAL(30,12) NOT NULL DEFAULT 0,
    realized_pnl DECIMAL(30,12) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id),
    CONSTRAINT chk_cm_paper_portfolio_nonnegative CHECK (available_capital >= 0 AND reserved_capital >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_capital_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    reservation_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    amount DECIMAL(30,12) NOT NULL,
    status VARCHAR(24) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_capital_reservation (organization_id,reservation_id),
    KEY ix_cm_capital_reservation_opportunity (organization_id,opportunity_id,status),
    CONSTRAINT fk_cm_reservation_opportunity FOREIGN KEY (organization_id,opportunity_id)
        REFERENCES tn_capital_market_opportunities (organization_id,opportunity_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_capital_reservation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_balances (
    organization_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    asset_key VARCHAR(190) NOT NULL,
    available_amount DECIMAL(30,12) NOT NULL DEFAULT 0,
    reserved_amount DECIMAL(30,12) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,venue_id,asset_key),
    CONSTRAINT chk_cm_paper_balance_nonnegative CHECK (available_amount >= 0 AND reserved_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_balance_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    reservation_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    asset_key VARCHAR(190) NOT NULL,
    amount DECIMAL(30,12) NOT NULL,
    status VARCHAR(24) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_paper_balance_reservation (organization_id,reservation_id),
    KEY ix_cm_paper_balance_reservation_opportunity (organization_id,opportunity_id,status),
    CONSTRAINT fk_cm_balance_reservation_opportunity FOREIGN KEY (organization_id,opportunity_id)
        REFERENCES tn_capital_market_opportunities (organization_id,opportunity_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_paper_balance_reservation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.tokenized_equity.enabled','Capital Markets Tokenized Equity research and paper vertical slice',1,100,'cm-tokenized-equity-v1');

INSERT IGNORE INTO capital_market_user_capabilities
    (organization_id,user_id,capability,status,granted_by)
SELECT u.organization_id,u.id,c.capability,'ACTIVE','cm-tokenized-equity-install'
FROM tn_users u
CROSS JOIN (
    SELECT 'capital_markets.opportunity.view' capability UNION ALL
    SELECT 'capital_markets.paper.execute'
) c
WHERE u.role='admin' AND u.status='active';

UPDATE cos_module_installations
SET installed_version='0.4.0',
    schema_version='0.4.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.3.0'
  AND schema_version='0.3.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000128_capital_markets_tokenized_equity_vertical_slice');
