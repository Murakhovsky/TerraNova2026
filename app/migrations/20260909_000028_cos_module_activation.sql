CREATE TABLE IF NOT EXISTS cos_organization_modules (
    organization_id VARCHAR(40) NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    configuration_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, module_id),
    KEY idx_cos_organization_modules_enabled (organization_id, enabled, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260909_000028_cos_module_activation');
