-- Atomic multi-SKU count sessions, with immutable native count links.
CREATE TABLE IF NOT EXISTS pm_stock_count_sessions (
 organization_id VARCHAR(190) NOT NULL,
 session_id CHAR(32) NOT NULL,
 location_id CHAR(32) NOT NULL,
 source_ref VARCHAR(191) NOT NULL,
 action_key VARCHAR(191) NOT NULL,
 actor_ref VARCHAR(100) NOT NULL,
 row_count INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,session_id),
 UNIQUE KEY uq_pm_session_origin (organization_id,location_id,source_ref),
 UNIQUE KEY uq_pm_session_action (organization_id,action_key),
 CONSTRAINT fk_pm_session_location FOREIGN KEY (organization_id,location_id) REFERENCES pm_locations (organization_id,location_id),
 CONSTRAINT ck_pm_session_size CHECK (row_count BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pm_stock_count_session_lines (
 organization_id VARCHAR(190) NOT NULL,
 session_id CHAR(32) NOT NULL,
 count_id CHAR(32) NOT NULL,
 PRIMARY KEY (organization_id,session_id,count_id),
 UNIQUE KEY uq_pm_session_count (organization_id,count_id),
 CONSTRAINT fk_pm_session_line_head FOREIGN KEY (organization_id,session_id) REFERENCES pm_stock_count_sessions (organization_id,session_id),
 CONSTRAINT fk_pm_session_line_count FOREIGN KEY (organization_id,count_id) REFERENCES pm_stock_counts (organization_id,count_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
