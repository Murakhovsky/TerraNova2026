-- Sales V0.6.7 / Epic 2 hardening
-- Adds explicit Lead disqualification semantics and durable Deal stage history.

ALTER TABLE tn_leads
    MODIFY status ENUM(
        'new', 'contacted', 'qualified', 'viewing', 'viewing_planned', 'negotiation',
        'won', 'lost', 'disqualified', 'spam', 'closed'
    ) NOT NULL DEFAULT 'new';

CREATE TABLE IF NOT EXISTS sales_deal_stage_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    deal_id BIGINT UNSIGNED NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    stage_id VARCHAR(40) NOT NULL,
    entered_at DATETIME NOT NULL,
    left_at DATETIME NULL,
    is_backfill TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_stage_history_current (organization_id, deal_id, left_at),
    KEY idx_sales_stage_history_funnel (organization_id, pipeline_id, stage_id, entered_at),
    KEY idx_sales_stage_history_deal (organization_id, deal_id, entered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- We cannot reconstruct historical stage-entry timestamps for legacy deals. Seed the
-- current stage from updated_at and explicitly mark it as estimated/backfilled.
INSERT INTO sales_deal_stage_history (
    organization_id, deal_id, pipeline_id, stage_id, entered_at, left_at, is_backfill
)
SELECT
    c.organization_id,
    c.id,
    c.pipeline_id,
    c.stage_id,
    COALESCE(c.updated_at, c.created_at, NOW()),
    NULL,
    1
FROM tn_client_cases c
WHERE c.pipeline_id IS NOT NULL
  AND c.pipeline_id <> ''
  AND c.stage_id IS NOT NULL
  AND c.stage_id <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM sales_deal_stage_history h
      WHERE h.organization_id = c.organization_id
        AND h.deal_id = c.id
        AND h.left_at IS NULL
  );

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260910_000030_sales_v067_epic2_hardening');
