CREATE TABLE IF NOT EXISTS cos_external_circuits (
    organization_id VARCHAR(64) NOT NULL,
    service_key VARCHAR(128) NOT NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    opened_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, service_key),
    KEY idx_cos_external_circuits_open (opened_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_tenant_execution_leases (
    lease_id VARCHAR(96) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (lease_id),
    KEY idx_cos_tenant_execution_leases_org_expiry (organization_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
