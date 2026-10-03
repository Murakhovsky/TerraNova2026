-- Production Cutover repair: the Lead List exposes a direct NEW -> QUALIFIED action.
-- V0.2-era Sales fixtures explicitly supported this shortcut, but the canonical V0.8
-- pipeline seed later generated adjacent transitions only. Restore the business contract
-- for every active Sales pipeline without weakening arbitrary stage-transition governance.
INSERT INTO sales_pipeline_transitions
    (id, organization_id, pipeline_id, from_stage_id, to_stage_id, requires_approval, conditions)
SELECT
    LEFT(SHA2(CONCAT(p.id, ':lead:new:qualified'), 256), 32),
    p.organization_id,
    p.id,
    source.id,
    target.id,
    0,
    JSON_ARRAY()
FROM sales_pipelines p
INNER JOIN sales_pipeline_stages source
    ON source.pipeline_id=p.id
    AND source.organization_id=p.organization_id
    AND source.code='NEW'
    AND source.status='ACTIVE'
INNER JOIN sales_pipeline_stages target
    ON target.pipeline_id=p.id
    AND target.organization_id=p.organization_id
    AND target.code='QUALIFIED'
    AND target.status='ACTIVE'
WHERE p.status='ACTIVE'
ON DUPLICATE KEY UPDATE
    requires_approval=VALUES(requires_approval),
    conditions=VALUES(conditions);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261003_000121_sales_lead_qualification_transition');
