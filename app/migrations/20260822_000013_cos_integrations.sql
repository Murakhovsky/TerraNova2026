CREATE TABLE IF NOT EXISTS cos_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    capability ENUM('CRM', 'TELEPHONY', 'MESSAGING', 'AI', 'STORAGE') NOT NULL,
    provider VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('ACTIVE', 'DISABLED', 'ERROR') NOT NULL DEFAULT 'ACTIVE',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    config JSON NULL,
    credentials_reference VARCHAR(255) NULL,
    last_health_check_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_integrations_org_provider (organization_id, capability, provider),
    KEY idx_cos_integrations_resolver (organization_id, capability, status, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_external_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    cos_reference VARCHAR(191) NOT NULL,
    external_url VARCHAR(700) NULL,
    external_version VARCHAR(100) NULL,
    last_synced_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_external_ref_external (organization_id, provider, entity_type, external_id),
    UNIQUE KEY uq_cos_external_ref_cos (organization_id, provider, entity_type, cos_reference),
    KEY idx_cos_external_refs_lookup (organization_id, entity_type, cos_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_sync_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    direction ENUM('INBOUND', 'OUTBOUND', 'BIDIRECTIONAL') NOT NULL,
    status ENUM('PENDING', 'SYNCED', 'CONFLICT', 'FAILED', 'IGNORED') NOT NULL DEFAULT 'PENDING',
    local_version VARCHAR(100) NULL,
    external_version VARCHAR(100) NULL,
    payload_hash CHAR(64) NULL,
    cursor_value VARCHAR(500) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    last_attempt_at DATETIME(6) NULL,
    synced_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_sync_state_entity (organization_id, provider, entity_type, external_id),
    KEY idx_cos_sync_state_work (status, last_attempt_at, attempts),
    KEY idx_cos_sync_state_org (organization_id, provider, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cos_integrations (
    organization_id, capability, provider, name, status, is_primary, config
)
VALUES (
    'default', 'CRM', 'aida', 'AIDA native CRM', 'ACTIVE', 1,
    JSON_OBJECT('mode', 'native', 'source_of_truth', 'aida')
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    status = VALUES(status),
    is_primary = VALUES(is_primary),
    config = VALUES(config),
    updated_at = CURRENT_TIMESTAMP(6);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000013_cos_integrations');
