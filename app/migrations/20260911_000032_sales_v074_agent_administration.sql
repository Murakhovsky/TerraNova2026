CREATE TABLE IF NOT EXISTS cos_agent_configurations (
    organization_id VARCHAR(40) NOT NULL,
    domain_name VARCHAR(80) NOT NULL,
    agent_name VARCHAR(160) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    profile VARCHAR(80) NOT NULL DEFAULT 'default',
    model VARCHAR(160) NULL,
    business_instructions TEXT NULL,
    context_sources JSON NOT NULL,
    allowed_actions JSON NOT NULL,
    confidence_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    ownership ENUM('SYSTEM', 'ADMIN') NOT NULL DEFAULT 'SYSTEM',
    configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
    system_fingerprint CHAR(64) NOT NULL,
    system_definition JSON NOT NULL,
    system_update_available TINYINT(1) NOT NULL DEFAULT 0,
    admin_modified_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, domain_name, agent_name),
    KEY idx_cos_agent_config_domain (organization_id, domain_name, enabled),
    CONSTRAINT fk_cos_agent_config_org
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT chk_cos_agent_confidence
        CHECK (confidence_threshold >= 0 AND confidence_threshold <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000032_sales_v074_agent_administration');
