CREATE TABLE IF NOT EXISTS sales_deal_stage_history (
    id VARCHAR(100) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    deal_id VARCHAR(100) NOT NULL,
    pipeline_id VARCHAR(40) NULL,
    from_stage_id VARCHAR(40) NULL,
    from_stage_code VARCHAR(100) NULL,
    to_stage_id VARCHAR(40) NOT NULL,
    to_stage_code VARCHAR(100) NOT NULL,
    entered_at DATETIME(6) NULL,
    left_at DATETIME(6) NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    source_event_id VARCHAR(40) NULL,
    correlation_id VARCHAR(40) NULL,
    history_quality ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED') NOT NULL,
    projected_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_deal_stage_history_event (organization_id, source_event_id),
    KEY idx_sales_deal_stage_history_deal (organization_id, deal_id, entered_at),
    KEY idx_sales_deal_stage_history_open (organization_id, deal_id, left_at),
    KEY idx_sales_deal_stage_history_transition (
        organization_id, pipeline_id, from_stage_code, to_stage_code, entered_at
    ),
    KEY idx_sales_deal_stage_history_quality (organization_id, history_quality, entered_at),
    CONSTRAINT fk_sales_deal_stage_history_org
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy current-state rows are intentionally timestamp-less. The system knows where
-- those Deals are now, but it does not know when they entered the stage.
INSERT IGNORE INTO sales_deal_stage_history (
    id, organization_id, deal_id, pipeline_id,
    from_stage_id, from_stage_code, to_stage_id, to_stage_code,
    entered_at, left_at, duration_seconds,
    source_event_id, correlation_id, history_quality
)
SELECT
    CONCAT(
        'estimated:',
        LEFT(SHA2(CONCAT(c.organization_id, ':', c.id, ':', COALESCE(c.pipeline_id, ''), ':', c.stage_id), 256), 64)
    ),
    c.organization_id,
    CAST(c.id AS CHAR),
    c.pipeline_id,
    NULL,
    NULL,
    c.stage_id,
    COALESCE(s.code, UPPER(c.stage)),
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    'ESTIMATED'
FROM tn_client_cases c
LEFT JOIN sales_pipeline_stages s
    ON s.id = c.stage_id AND s.organization_id = c.organization_id
WHERE c.stage_id IS NOT NULL
  AND COALESCE(s.code, UPPER(c.stage), '') <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM sales_deal_stage_history h
      WHERE h.organization_id = c.organization_id
        AND h.deal_id = CAST(c.id AS CHAR)
        AND h.left_at IS NULL
  );

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260913_000044_sales_v081_historical_stage_history');
