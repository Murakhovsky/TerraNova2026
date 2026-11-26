CREATE TABLE IF NOT EXISTS marketing_optimization_schedules (
    organization_id VARCHAR(190) NOT NULL,
    profile_id VARCHAR(64) NOT NULL,
    configuration_hash CHAR(64) NOT NULL,
    cycle_date DATE DEFAULT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'NEW',
    signal_hash CHAR(64) DEFAULT NULL,
    signal_json JSON DEFAULT NULL,
    goal_id VARCHAR(64) DEFAULT NULL,
    plan_id VARCHAR(64) DEFAULT NULL,
    run_id VARCHAR(64) DEFAULT NULL,
    evaluation_id VARCHAR(64) DEFAULT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261126_009023_marketing_optimization_schedules');
