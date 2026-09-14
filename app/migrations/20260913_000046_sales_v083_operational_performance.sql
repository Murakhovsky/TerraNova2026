-- V0.8.3 repairs the V0.6.7/V0.8.1 stage-history schema collision before
-- introducing manager-at-time history and operational performance facts.

SET @sales_stage_history_is_legacy := (
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
SET @sales_stage_history_archive_exists := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales_deal_stage_history_v067_legacy'
);
SET @sales_stage_history_repair_sql := CASE
    WHEN @sales_stage_history_is_legacy > 0 AND @sales_stage_history_archive_exists = 0
        THEN 'RENAME TABLE sales_deal_stage_history TO sales_deal_stage_history_v067_legacy'
    WHEN @sales_stage_history_is_legacy > 0 AND @sales_stage_history_archive_exists > 0
        THEN 'DROP TABLE sales_deal_stage_history'
    ELSE 'SELECT 1'
END;
PREPARE sales_stage_history_repair_stmt FROM @sales_stage_history_repair_sql;
EXECUTE sales_stage_history_repair_stmt;
DEALLOCATE PREPARE sales_stage_history_repair_stmt;

CREATE TABLE IF NOT EXISTS sales_deal_stage_history (
    id VARCHAR(100) NOT NULL DEFAULT '',
    organization_id VARCHAR(40) NOT NULL,
    deal_id VARCHAR(100) NOT NULL,
    pipeline_id VARCHAR(40) NULL,
    from_stage_id VARCHAR(40) NULL,
    from_stage_code VARCHAR(100) NULL,
    to_stage_id VARCHAR(40) NOT NULL DEFAULT '',
    to_stage_code VARCHAR(100) NOT NULL DEFAULT '',
    entered_at DATETIME(6) NULL,
    left_at DATETIME(6) NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    source_event_id VARCHAR(40) NULL,
    correlation_id VARCHAR(40) NULL,
    history_quality ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED') NOT NULL DEFAULT 'ESTIMATED',
    projected_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    -- Compatibility-only columns for the one remaining V0.6.7 create-case insert path.
    -- The trigger below marks those rows ESTIMATED and the canonical DealCreated event replaces them.
    stage_id VARCHAR(40) NULL,
    is_backfill TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_deal_stage_history_event (organization_id, source_event_id),
    KEY idx_sales_deal_stage_history_deal (organization_id, deal_id, entered_at),
    KEY idx_sales_deal_stage_history_open (organization_id, deal_id, left_at),
    KEY idx_sales_deal_stage_history_transition (
        organization_id, pipeline_id, from_stage_code, to_stage_code, entered_at
    ),
    KEY idx_sales_deal_stage_history_quality (organization_id, history_quality, entered_at),
    KEY idx_sales_deal_stage_history_duration (organization_id, pipeline_id, to_stage_code, left_at, history_quality),
    CONSTRAINT fk_sales_deal_stage_history_org_v083
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sales_stage_history_has_stage_id := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_deal_stage_history' AND COLUMN_NAME = 'stage_id'
);
SET @sales_stage_history_add_stage_id_sql := IF(
    @sales_stage_history_has_stage_id = 0,
    'ALTER TABLE sales_deal_stage_history ADD COLUMN stage_id VARCHAR(40) NULL AFTER projected_at',
    'SELECT 1'
);
PREPARE sales_stage_history_add_stage_id_stmt FROM @sales_stage_history_add_stage_id_sql;
EXECUTE sales_stage_history_add_stage_id_stmt;
DEALLOCATE PREPARE sales_stage_history_add_stage_id_stmt;

SET @sales_stage_history_has_is_backfill := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_deal_stage_history' AND COLUMN_NAME = 'is_backfill'
);
SET @sales_stage_history_add_backfill_sql := IF(
    @sales_stage_history_has_is_backfill = 0,
    'ALTER TABLE sales_deal_stage_history ADD COLUMN is_backfill TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_id',
    'SELECT 1'
);
PREPARE sales_stage_history_add_backfill_stmt FROM @sales_stage_history_add_backfill_sql;
EXECUTE sales_stage_history_add_backfill_stmt;
DEALLOCATE PREPARE sales_stage_history_add_backfill_stmt;

-- Permit the legacy create-case INSERT to reach the compatibility trigger. Canonical writers
-- always supply id/to_stage/history_quality explicitly, so these defaults never invent facts.
ALTER TABLE sales_deal_stage_history
    MODIFY id VARCHAR(100) NOT NULL DEFAULT '',
    MODIFY to_stage_id VARCHAR(40) NOT NULL DEFAULT '',
    MODIFY to_stage_code VARCHAR(100) NOT NULL DEFAULT '',
    MODIFY history_quality ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED') NOT NULL DEFAULT 'ESTIMATED';

DROP TRIGGER IF EXISTS trg_sales_stage_history_legacy_insert_v083;
CREATE TRIGGER trg_sales_stage_history_legacy_insert_v083
BEFORE INSERT ON sales_deal_stage_history
FOR EACH ROW
SET
    NEW.id = IF(
        NEW.source_event_id IS NULL AND NEW.stage_id IS NOT NULL AND NEW.to_stage_id = '',
        CONCAT('legacy-estimated:', REPLACE(UUID(), '-', '')),
        NEW.id
    ),
    NEW.to_stage_id = IF(
        NEW.source_event_id IS NULL AND NEW.stage_id IS NOT NULL AND NEW.to_stage_id = '',
        NEW.stage_id,
        NEW.to_stage_id
    ),
    NEW.to_stage_code = IF(
        NEW.source_event_id IS NULL AND NEW.stage_id IS NOT NULL AND NEW.to_stage_code = '',
        COALESCE((
            SELECT s.code
            FROM sales_pipeline_stages s
            WHERE CONVERT(s.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  = CONVERT(NEW.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
              AND CONVERT(s.pipeline_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  = CONVERT(NEW.pipeline_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
              AND CONVERT(s.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  = CONVERT(NEW.stage_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LIMIT 1
        ), 'UNKNOWN'),
        NEW.to_stage_code
    ),
    NEW.history_quality = IF(
        NEW.source_event_id IS NULL AND NEW.stage_id IS NOT NULL,
        'ESTIMATED',
        NEW.history_quality
    );

-- After a repair, current state is known but its entry timestamp is not. Seed it explicitly
-- as ESTIMATED; the durable canonical replay upgrades what can actually be proven.
-- Older databases may still use utf8mb4_general_ci on Sales source tables, therefore all
-- cross-table string comparisons are normalized explicitly during this compatibility upgrade.
INSERT IGNORE INTO sales_deal_stage_history (
    id, organization_id, deal_id, pipeline_id,
    from_stage_id, from_stage_code, to_stage_id, to_stage_code,
    entered_at, left_at, duration_seconds,
    source_event_id, correlation_id, history_quality
)
SELECT
    CONCAT('estimated:', LEFT(SHA2(CONCAT(c.organization_id, ':', c.id, ':', COALESCE(c.pipeline_id, ''), ':', c.stage_id), 256), 64)),
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
      SELECT 1 FROM sales_deal_stage_history h
      WHERE CONVERT(h.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND CONVERT(h.deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(CAST(c.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND h.left_at IS NULL
  );

CREATE TABLE IF NOT EXISTS sales_deal_owner_history (
    id VARCHAR(100) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    deal_id VARCHAR(100) NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NULL,
    unassigned_at DATETIME(6) NULL,
    source_event_id VARCHAR(40) NULL,
    correlation_id VARCHAR(40) NULL,
    history_quality ENUM('COMPLETE', 'PARTIAL', 'ESTIMATED') NOT NULL,
    projected_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_deal_owner_history_event (organization_id, source_event_id),
    KEY idx_sales_deal_owner_history_deal (organization_id, deal_id, assigned_at),
    KEY idx_sales_deal_owner_history_owner (organization_id, owner_user_id, assigned_at),
    KEY idx_sales_deal_owner_history_open (organization_id, deal_id, unassigned_at),
    KEY idx_sales_deal_owner_history_quality (organization_id, history_quality, assigned_at),
    CONSTRAINT fk_sales_deal_owner_history_org
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility capture for mutation paths that still update tn_client_cases directly.
-- These facts are deliberately PARTIAL. Canonical DEAL_OWNER_ASSIGNED events replace the
-- matching open PARTIAL row with COMPLETE provenance when the supported use case is used.
DROP TRIGGER IF EXISTS trg_sales_owner_history_case_insert_v083;
DROP TRIGGER IF EXISTS trg_sales_owner_history_case_close_v083;
DROP TRIGGER IF EXISTS trg_sales_owner_history_case_update_v083;

CREATE TRIGGER trg_sales_owner_history_case_insert_v083
AFTER INSERT ON tn_client_cases
FOR EACH ROW
INSERT IGNORE INTO sales_deal_owner_history (
    id, organization_id, deal_id, owner_user_id, assigned_at, unassigned_at,
    source_event_id, correlation_id, history_quality
)
SELECT
    CONCAT('db-create-owner:', LEFT(SHA2(CONCAT(NEW.organization_id, ':', NEW.id, ':', NEW.assigned_user_id, ':', COALESCE(NEW.created_at, NOW(6))), 256), 64)),
    NEW.organization_id, CAST(NEW.id AS CHAR), NEW.assigned_user_id, COALESCE(NEW.created_at, NOW(6)), NULL,
    NULL, NULL, 'PARTIAL'
WHERE NEW.assigned_user_id IS NOT NULL;

CREATE TRIGGER trg_sales_owner_history_case_close_v083
BEFORE UPDATE ON tn_client_cases
FOR EACH ROW
UPDATE sales_deal_owner_history
SET unassigned_at = NOW(6),
    history_quality = IF(history_quality = 'COMPLETE', 'COMPLETE', 'PARTIAL')
WHERE CONVERT(organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
      = CONVERT(NEW.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
  AND CONVERT(deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
      = CONVERT(CAST(NEW.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
  AND unassigned_at IS NULL
  AND NOT (OLD.assigned_user_id <=> NEW.assigned_user_id);

CREATE TRIGGER trg_sales_owner_history_case_update_v083
AFTER UPDATE ON tn_client_cases
FOR EACH ROW
INSERT INTO sales_deal_owner_history (
    id, organization_id, deal_id, owner_user_id, assigned_at, unassigned_at,
    source_event_id, correlation_id, history_quality
)
SELECT
    CONCAT('db-owner:', REPLACE(UUID(), '-', '')),
    NEW.organization_id, CAST(NEW.id AS CHAR), NEW.assigned_user_id, NOW(6), NULL,
    NULL, NULL, 'PARTIAL'
WHERE NEW.assigned_user_id IS NOT NULL
  AND NOT (OLD.assigned_user_id <=> NEW.assigned_user_id);

INSERT IGNORE INTO sales_deal_owner_history (
    id, organization_id, deal_id, owner_user_id,
    assigned_at, unassigned_at, source_event_id, correlation_id, history_quality
)
SELECT
    CONCAT('estimated-owner:', LEFT(SHA2(CONCAT(c.organization_id, ':', c.id, ':', c.assigned_user_id), 256), 64)),
    c.organization_id,
    CAST(c.id AS CHAR),
    c.assigned_user_id,
    NULL,
    NULL,
    NULL,
    NULL,
    'ESTIMATED'
FROM tn_client_cases c
WHERE c.assigned_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM sales_deal_owner_history h
      WHERE CONVERT(h.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(c.organization_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND CONVERT(h.deal_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            = CONVERT(CAST(c.id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
        AND h.unassigned_at IS NULL
  );

CREATE INDEX idx_sales_communications_response_v083
    ON sales_communications (organization_id, deal_id, channel, occurred_at, direction);

CREATE INDEX idx_sales_followup_due_v083
    ON tn_client_case_activities (organization_id, activity_type, due_at, completed_at, client_case_id);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260913_000046_sales_v083_operational_performance');
