-- V0.8.1 introduces the canonical event-owned Deal stage history projection.
-- V0.6.7 already used the same table name with a different current-state schema.
-- Repair that collision before CREATE TABLE IF NOT EXISTS so upgrades from an
-- existing V0.6.7 database do not try to write V0.8.1 columns into the legacy table.
SET @sales_stage_history_is_legacy_v081 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales_deal_stage_history'
      AND COLUMN_NAME = 'is_backfill'
      AND NOT EXISTS (
          SELECT 1 FROM information_schema.COLUMNS c2
          WHERE c2.TABLE_SCHEMA = DATABASE()
            AND c2.TABLE_NAME = 'sales_deal_stage_history'
            AND c2.COLUMN_NAME = 'history_quality'
      )
);
SET @sales_stage_history_archive_exists_v081 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales_deal_stage_history_v067_legacy'
);
SET @sales_stage_history_repair_sql_v081 := CASE
    WHEN @sales_stage_history_is_legacy_v081 > 0 AND @sales_stage_history_archive_exists_v081 = 0
        THEN 'RENAME TABLE sales_deal_stage_history TO sales_deal_stage_history_v067_legacy'
    WHEN @sales_stage_history_is_legacy_v081 > 0 AND @sales_stage_history_archive_exists_v081 > 0
        THEN 'DROP TABLE sales_deal_stage_history'
    ELSE 'SELECT 1'
END;
PREPARE sales_stage_history_repair_stmt_v081 FROM @sales_stage_history_repair_sql_v081;
EXECUTE sales_stage_history_repair_stmt_v081;
DEALLOCATE PREPARE sales_stage_history_repair_stmt_v081;

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
-- those Deals are now, but it does not know when they entered the stage. Explicitly
-- normalize comparison collations because older dev databases may still carry
-- utf8mb4_general_ci on Sales tables while the canonical V0.8.1 projection uses
-- utf8mb4_unicode_ci.
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
    ON CONVERT(s.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
       = CONVERT(c.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
   AND CONVERT(s.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
       = CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
WHERE c.stage_id IS NOT NULL
  AND CHAR_LENGTH(COALESCE(s.code, UPPER(c.stage), '')) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM sales_deal_stage_history h
      WHERE CONVERT(h.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND CONVERT(h.deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(CAST(c.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND h.left_at IS NULL
  );

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260913_000044_sales_v081_historical_stage_history');
