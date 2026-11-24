-- Domain-owned discovery checkpoint and admitted work receipts; Kernel cos_jobs owns execution/retries.
CREATE TABLE IF NOT EXISTS sales_order_sync_runs (
 organization_id VARCHAR(40) NOT NULL,
 run_id CHAR(32) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(32) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 mode VARCHAR(16) NOT NULL,
 cutoff_at DATETIME(6) NOT NULL,
 cursor_value VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
 historical_access VARCHAR(32) NULL,
 discovery_state VARCHAR(16) NOT NULL DEFAULT 'pending',
 pages INT UNSIGNED NOT NULL DEFAULT 0,
 review_reason VARCHAR(64) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_sync_tasks (
 organization_id VARCHAR(40) NOT NULL,
 run_id CHAR(32) NOT NULL,
 task_key CHAR(64) NOT NULL,
 job_id CHAR(32) NOT NULL,
 kind VARCHAR(16) NOT NULL,
 input_json JSON NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'pending',
 outcome VARCHAR(32) NULL,
 blocked_reason VARCHAR(64) NULL,
 order_id CHAR(32) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,run_id,task_key),
 UNIQUE KEY uq_sales_order_sync_job(organization_id,job_id),
 KEY idx_sales_order_sync_state(organization_id,run_id,state,task_key),
 CONSTRAINT fk_sales_order_sync_run FOREIGN KEY(organization_id,run_id) REFERENCES sales_order_sync_runs(organization_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261125_009006_sales_order_sync_runs');
