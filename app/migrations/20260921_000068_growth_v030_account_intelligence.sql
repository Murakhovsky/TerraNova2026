CREATE TABLE IF NOT EXISTS tn_growth_icp_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    criteria_json JSON NOT NULL,
    activated_at DATETIME(6) NULL,
    archived_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_icp_revision (organization_id,profile_id,revision),
    KEY ix_growth_icp_status (organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    name VARCHAR(220) NOT NULL,
    canonical_domain VARCHAR(191) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_account (organization_id,account_id),
    UNIQUE KEY uq_growth_account_domain (organization_id,canonical_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_account_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    snapshot_id VARCHAR(80) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    firmographics_json JSON NOT NULL,
    technologies_json JSON NOT NULL,
    hiring_json JSON NOT NULL,
    recent_changes_json JSON NOT NULL,
    signal_types_json JSON NOT NULL,
    source_references_json JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    captured_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_account_snapshot (organization_id,snapshot_id),
    KEY ix_growth_account_snapshot_account (organization_id,account_id,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_account_icp_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    match_id VARCHAR(80) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    profile_revision INT UNSIGNED NOT NULL,
    fit_score TINYINT UNSIGNED NOT NULL,
    fit_json JSON NOT NULL,
    matched_json JSON NOT NULL,
    gaps_json JSON NOT NULL,
    scored_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_account_icp_match (organization_id,match_id),
    KEY ix_growth_account_icp_score (organization_id,profile_id,profile_revision,fit_score,scored_at),
    KEY ix_growth_account_icp_account (organization_id,account_id,scored_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.3.0',
    schema_version='0.3.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.2.0'
  AND schema_version='0.2.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260921_000068_growth_v030_account_intelligence');
