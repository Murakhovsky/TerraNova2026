CREATE TABLE IF NOT EXISTS sales_stage_metric_thresholds (
    organization_id VARCHAR(40) NOT NULL,
    stage_id VARCHAR(40) NOT NULL,
    stuck_after_seconds BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, stage_id),
    KEY idx_sales_stage_metric_threshold_stage (stage_id),
    CONSTRAINT fk_sales_stage_metric_threshold_org
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_stage_metric_threshold_stage
        FOREIGN KEY (stage_id) REFERENCES sales_pipeline_stages (id) ON DELETE CASCADE,
    CONSTRAINT chk_sales_stage_metric_threshold_positive CHECK (stuck_after_seconds > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_sales_deal_stage_history_duration
    ON sales_deal_stage_history (organization_id, pipeline_id, left_at, to_stage_code, history_quality, duration_seconds);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260913_000045_sales_v082_funnel_metrics');
