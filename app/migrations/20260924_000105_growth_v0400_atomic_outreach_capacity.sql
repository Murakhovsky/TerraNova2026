CREATE TABLE IF NOT EXISTS tn_growth_engagement_capacity_locks (
    organization_id VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.40.0',
    schema_version='0.40.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.39.0'
  AND schema_version='0.39.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000105_growth_v0400_atomic_outreach_capacity');
