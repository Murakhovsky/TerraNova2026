CREATE TABLE IF NOT EXISTS tn_growth_contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    contact_id VARCHAR(80) NOT NULL,
    full_name VARCHAR(220) NOT NULL,
    identity_type VARCHAR(40) NOT NULL,
    identity_value VARCHAR(500) NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    source_references_json JSON NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_contact (organization_id,contact_id),
    UNIQUE KEY uq_growth_contact_identity (organization_id,identity_type,identity_hash),
    KEY ix_growth_contact_name (organization_id,full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_account_contacts (
    organization_id VARCHAR(64) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    contact_id VARCHAR(80) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,account_id,contact_id),
    KEY ix_growth_account_contact_reverse (organization_id,contact_id,account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_contact_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    snapshot_id VARCHAR(80) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    contact_id VARCHAR(80) NOT NULL,
    title VARCHAR(220) NULL,
    department VARCHAR(120) NULL,
    seniority VARCHAR(80) NULL,
    buying_roles_json JSON NOT NULL,
    relationship_strength VARCHAR(40) NOT NULL,
    relationship_reason VARCHAR(1000) NOT NULL,
    source_references_json JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    captured_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_contact_snapshot (organization_id,snapshot_id),
    KEY ix_growth_contact_snapshot_account (organization_id,account_id,contact_id,captured_at),
    KEY ix_growth_contact_snapshot_contact (organization_id,contact_id,captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_buying_committee_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    assessment_id VARCHAR(80) NOT NULL,
    account_id VARCHAR(80) NOT NULL,
    required_roles_json JSON NOT NULL,
    coverage_json JSON NOT NULL,
    gaps_json JSON NOT NULL,
    champion_contact_ids_json JSON NOT NULL,
    blocker_contact_ids_json JSON NOT NULL,
    weak_relationship_contact_ids_json JSON NOT NULL,
    coverage_score TINYINT UNSIGNED NOT NULL,
    relationship_score TINYINT UNSIGNED NOT NULL,
    snapshot_ids_json JSON NOT NULL,
    model_version VARCHAR(120) NOT NULL,
    assessed_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_committee_assessment (organization_id,assessment_id),
    KEY ix_growth_committee_account (organization_id,account_id,assessed_at),
    KEY ix_growth_committee_score (organization_id,coverage_score,relationship_score,assessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.4.0',
    schema_version='0.4.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.3.0'
  AND schema_version='0.3.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000069_growth_v040_buying_committee');
