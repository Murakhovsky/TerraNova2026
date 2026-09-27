CREATE TABLE IF NOT EXISTS tn_growth_json_signal_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    url_hash CHAR(64) NOT NULL,
    auth_mode VARCHAR(40) NOT NULL,
    credential_reference VARCHAR(500) NOT NULL,
    api_key_header VARCHAR(80) NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    signal_type VARCHAR(120) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_json_signal_source (organization_id,source_id),
    UNIQUE KEY uq_growth_json_signal_source_mapping (
        organization_id,url_hash,subject_type,subject_id,signal_type
    ),
    KEY ix_growth_json_signal_source_enabled (organization_id,enabled,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.28.0',
    schema_version='0.28.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.27.0'
  AND schema_version='0.26.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000093_growth_v0280_credentialed_json_collector');
