CREATE TABLE IF NOT EXISTS tn_capital_market_data_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NULL,
    adapter_type VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    roles_json JSON NOT NULL,
    credentials_reference VARCHAR(190) NULL,
    rate_limit_policy_json JSON NOT NULL,
    reconnect_policy_json JSON NOT NULL,
    health_policy_json JSON NOT NULL,
    quality_policy_json JSON NULL,
    license_profile VARCHAR(120) NOT NULL,
    metadata_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_source (organization_id,source_id),
    KEY ix_cm_market_source_enabled (organization_id,enabled,priority),
    KEY ix_cm_market_source_venue (organization_id,venue_id),
    CONSTRAINT fk_cm_market_source_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_market_source_enabled CHECK (enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_source_health (
    organization_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    connection_state VARCHAR(32) NOT NULL,
    last_event_at DATETIME(6) NULL,
    last_heartbeat_at DATETIME(6) NULL,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    queue_lag INT UNSIGNED NOT NULL DEFAULT 0,
    clock_reliable TINYINT(1) NOT NULL DEFAULT 1,
    last_error VARCHAR(1000) NULL,
    messages_per_second DECIMAL(28,8) NULL,
    last_latency_ms BIGINT UNSIGNED NULL,
    error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reconnect_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rate_limit_state VARCHAR(24) NOT NULL DEFAULT 'UNKNOWN',
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,source_id),
    CONSTRAINT fk_cm_market_health_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT chk_cm_market_health_clock CHECK (clock_reliable IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    subscription_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NULL,
    instrument_id VARCHAR(190) NOT NULL,
    target_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin
        GENERATED ALWAYS AS (
            SHA2(CONCAT_WS(CHAR(31),organization_id,source_id,COALESCE(venue_id,''),instrument_id,data_type),256)
        ) STORED,
    data_type VARCHAR(48) NOT NULL,
    status VARCHAR(24) NOT NULL,
    subscribed_at DATETIME(6) NOT NULL,
    last_event_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_subscription_id (organization_id,subscription_id),
    UNIQUE KEY uq_cm_market_subscription_target (target_fingerprint),
    KEY ix_cm_market_subscription_active (organization_id,source_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tn_capital_market_subscriptions
    ADD CONSTRAINT fk_cm_market_subscription_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE CASCADE;

ALTER TABLE tn_capital_market_subscriptions
    ADD CONSTRAINT fk_cm_market_subscription_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE tn_capital_market_subscriptions
    ADD CONSTRAINT fk_cm_market_subscription_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000125_capital_markets_market_sources');
