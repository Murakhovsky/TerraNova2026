CREATE TABLE IF NOT EXISTS tn_growth_market_universes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    universe_id VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    source_type VARCHAR(80) NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    source_url_hash CHAR(64) NOT NULL,
    auth_mode VARCHAR(40) NOT NULL,
    credential_reference VARCHAR(500) NOT NULL,
    api_key_header VARCHAR(80) NULL,
    profile_id VARCHAR(80) NOT NULL,
    profile_revision INT UNSIGNED NOT NULL,
    min_icp_fit TINYINT UNSIGNED NOT NULL DEFAULT 70,
    opportunity_type VARCHAR(80) NOT NULL,
    growth_mode VARCHAR(40) NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    cursor TEXT NULL,
    last_run_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_market_universe (organization_id,universe_id),
    KEY ix_growth_market_universe_scheduler (enabled,updated_at),
    KEY ix_growth_market_universe_icp (organization_id,profile_id,profile_revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_market_discovery_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    universe_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    requested_limit INT UNSIGNED NOT NULL,
    collected_count INT UNSIGNED NOT NULL DEFAULT 0,
    account_count INT UNSIGNED NOT NULL DEFAULT 0,
    existing_count INT UNSIGNED NOT NULL DEFAULT 0,
    monitored_count INT UNSIGNED NOT NULL DEFAULT 0,
    opportunity_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_cursor TEXT NULL,
    error_summary VARCHAR(2000) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_market_run (organization_id,run_id),
    KEY ix_growth_market_run_universe (organization_id,universe_id,started_at),
    KEY ix_growth_market_run_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_market_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    universe_id VARCHAR(80) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    external_key_hash CHAR(64) NOT NULL,
    fit_score TINYINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    source_reference VARCHAR(500) NOT NULL,
    trigger_signal_id VARCHAR(80) NULL,
    candidate_id VARCHAR(80) NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_market_membership (organization_id,universe_id,account_id),
    KEY ix_growth_market_membership_account (organization_id,account_id,status),
    KEY ix_growth_market_membership_opportunity (organization_id,universe_id,status,fit_score),
    KEY ix_growth_market_membership_candidate (organization_id,candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.48.0',
    schema_version='0.48.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.47.0'
  AND schema_version='0.47.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000113_growth_v0480_market_discovery');
