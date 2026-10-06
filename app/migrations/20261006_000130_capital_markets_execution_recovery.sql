CREATE TABLE IF NOT EXISTS tn_capital_market_execution_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    plan_id VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_execution_plan (organization_id,plan_id),
    KEY ix_cm_execution_plan_opportunity (organization_id,opportunity_id,status),
    CONSTRAINT fk_cm_execution_plan_opportunity FOREIGN KEY (organization_id,opportunity_id)
        REFERENCES tn_capital_market_opportunities (organization_id,opportunity_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    order_id VARCHAR(190) NOT NULL,
    execution_id VARCHAR(190) NOT NULL,
    leg_id VARCHAR(190) NOT NULL,
    state VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_paper_order (organization_id,order_id),
    UNIQUE KEY uq_cm_paper_order_idempotency (organization_id,idempotency_key),
    KEY ix_cm_paper_order_execution (organization_id,execution_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_paper_fills (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    fill_id VARCHAR(190) NOT NULL,
    order_id VARCHAR(190) NOT NULL,
    execution_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_paper_fill (organization_id,fill_id),
    UNIQUE KEY uq_cm_paper_fill_idempotency (organization_id,idempotency_key),
    KEY ix_cm_paper_fill_execution (organization_id,execution_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    position_id VARCHAR(190) NOT NULL,
    portfolio_id VARCHAR(190) NOT NULL,
    strategy_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    status VARCHAR(24) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_position (organization_id,position_id),
    UNIQUE KEY uq_cm_position_scope (organization_id,portfolio_id,strategy_id,instrument_id,venue_id),
    KEY ix_cm_position_status (organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000130_capital_markets_execution_recovery');
