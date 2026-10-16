CREATE TABLE IF NOT EXISTS cos_kernel_execution_rollouts (
    organization_id VARCHAR(40) NOT NULL,
    runtime_kind ENUM('agent','tool') NOT NULL,
    mode ENUM('off','canonical') NOT NULL DEFAULT 'off',
    approved_by VARCHAR(100) NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,runtime_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261015_000144_kernel_execution_rollouts');
