CREATE TABLE IF NOT EXISTS marketing_attribution_schedules (
    organization_id VARCHAR(190) NOT NULL,
    profile_id VARCHAR(64) NOT NULL,
    configuration_hash CHAR(64) NOT NULL,
    cycle_date DATE DEFAULT NULL,
    window_start DATE DEFAULT NULL,
    window_end DATE DEFAULT NULL,
    after_cursor VARCHAR(32) DEFAULT NULL,
    plan_id VARCHAR(64) DEFAULT NULL,
    cycle_exhausted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261126_009020_marketing_attribution_schedules');
