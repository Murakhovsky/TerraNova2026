CREATE TABLE IF NOT EXISTS cos_module_installations (
    organization_id VARCHAR(64) NOT NULL,
    module_id VARCHAR(64) NOT NULL,
    status ENUM('INSTALLED', 'UNINSTALLED') NOT NULL DEFAULT 'INSTALLED',
    installed_version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(32) NOT NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, module_id),
    KEY idx_cos_module_installations_status (organization_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
