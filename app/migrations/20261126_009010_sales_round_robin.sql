CREATE TABLE IF NOT EXISTS sales_round_robin_cursors (
 organization_id VARCHAR(40) NOT NULL,
 team_id VARCHAR(40) NOT NULL,
 pool_hash CHAR(64) NOT NULL,
 next_slot BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(organization_id,team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO tn_migrations(migration) VALUES ('20261126_009010_sales_round_robin');
