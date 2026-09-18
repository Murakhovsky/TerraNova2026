-- Symfony Sales Wave 3 isolated deterministic automation fixtures.
-- Extends Wave 2 with pipeline SLA state needed by the canonical Sales detectors.
-- The scan itself remains tenant-scoped and writes only canonical Domain Events + Outbox rows.

CREATE TABLE sales_stage_metric_thresholds (
    organization_id VARCHAR(190) NOT NULL,
    stage_id BIGINT UNSIGNED NOT NULL,
    stuck_after_seconds BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, stage_id)
);

CREATE TABLE sales_deal_stage_history (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    deal_id VARCHAR(100) NOT NULL,
    pipeline_id BIGINT UNSIGNED NULL,
    from_stage_id BIGINT UNSIGNED NULL,
    from_stage_code VARCHAR(100) NULL,
    to_stage_id BIGINT UNSIGNED NOT NULL,
    to_stage_code VARCHAR(100) NOT NULL,
    entered_at DATETIME(6) NULL,
    left_at DATETIME(6) NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    source_event_id VARCHAR(64) NULL,
    correlation_id VARCHAR(128) NULL,
    history_quality VARCHAR(32) NOT NULL,
    projected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_wave3_history_open (organization_id, deal_id, left_at)
);

INSERT INTO sales_stage_metric_thresholds
    (organization_id,stage_id,stuck_after_seconds,updated_at)
VALUES
    ('default',10,86400,NOW(6)),
    ('other-org',20,86400,NOW(6));

INSERT INTO sales_deal_stage_history
    (id,organization_id,deal_id,pipeline_id,from_stage_id,from_stage_code,to_stage_id,to_stage_code,
     entered_at,left_at,duration_seconds,source_event_id,correlation_id,history_quality,projected_at)
VALUES
    ('wave3-default-101','default','101',1,NULL,NULL,10,'NEW',
     NOW(6)-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,'COMPLETE',NOW(6)),
    ('wave3-other-201','other-org','201',2,NULL,NULL,20,'NEW',
     NOW(6)-INTERVAL 3 DAY,NULL,NULL,NULL,NULL,'COMPLETE',NOW(6));
