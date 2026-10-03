-- Remediate 20261003_000121 without rewriting an already-applied migration.
-- The earlier repair could overwrite tenant-owned NEW -> QUALIFIED governance.
-- The latest TRANSITION configuration revision is the canonical tenant snapshot.

CREATE TEMPORARY TABLE tmp_sales_latest_transition_revisions (
    organization_id VARCHAR(40) NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (organization_id, pipeline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_sales_latest_transition_revisions (organization_id, pipeline_id, revision_id)
SELECT organization_id, entity_id, MAX(id)
FROM cos_configuration_revisions
WHERE domain_name='sales'
  AND configuration_type='TRANSITION'
GROUP BY organization_id, entity_id;

CREATE TEMPORARY TABLE tmp_sales_transition_revision_policy (
    organization_id VARCHAR(40) NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    from_stage_id VARCHAR(40) NOT NULL,
    to_stage_id VARCHAR(40) NOT NULL,
    requires_approval TINYINT(1) NOT NULL,
    conditions JSON NOT NULL,
    PRIMARY KEY (organization_id, pipeline_id, from_stage_id, to_stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_sales_transition_revision_policy (
    organization_id,
    pipeline_id,
    from_stage_id,
    to_stage_id,
    requires_approval,
    conditions
)
SELECT
    revision.organization_id,
    revision.entity_id,
    edge.from_stage_id,
    edge.to_stage_id,
    COALESCE(edge.requires_approval, 0),
    COALESCE(
        JSON_EXTRACT(revision.after_payload, CONCAT('$[', edge.ordinality - 1, '].conditions')),
        JSON_ARRAY()
    )
FROM cos_configuration_revisions revision
INNER JOIN tmp_sales_latest_transition_revisions latest
    ON latest.revision_id=revision.id
CROSS JOIN JSON_TABLE(
    revision.after_payload,
    '$[*]' COLUMNS (
        ordinality FOR ORDINALITY,
        from_stage_id VARCHAR(40) PATH '$.from_stage_id',
        to_stage_id VARCHAR(40) PATH '$.to_stage_id',
        requires_approval TINYINT PATH '$.requires_approval'
    )
) AS edge
WHERE edge.from_stage_id IS NOT NULL
  AND edge.to_stage_id IS NOT NULL;

-- If the latest tenant snapshot intentionally omitted NEW -> QUALIFIED,
-- remove the edge that 000121 may have injected.
DELETE current_transition
FROM sales_pipeline_transitions current_transition
INNER JOIN sales_pipelines pipeline
    ON pipeline.id=current_transition.pipeline_id
    AND pipeline.organization_id=current_transition.organization_id
INNER JOIN sales_pipeline_stages source_stage
    ON source_stage.id=current_transition.from_stage_id
    AND source_stage.pipeline_id=current_transition.pipeline_id
    AND source_stage.organization_id=current_transition.organization_id
INNER JOIN sales_pipeline_stages target_stage
    ON target_stage.id=current_transition.to_stage_id
    AND target_stage.pipeline_id=current_transition.pipeline_id
    AND target_stage.organization_id=current_transition.organization_id
INNER JOIN tmp_sales_latest_transition_revisions latest
    ON latest.organization_id=current_transition.organization_id
    AND latest.pipeline_id=current_transition.pipeline_id
LEFT JOIN tmp_sales_transition_revision_policy intended
    ON intended.organization_id=current_transition.organization_id
    AND intended.pipeline_id=current_transition.pipeline_id
    AND intended.from_stage_id=current_transition.from_stage_id
    AND intended.to_stage_id=current_transition.to_stage_id
WHERE pipeline.status='ACTIVE'
  AND source_stage.code='NEW'
  AND target_stage.code='QUALIFIED'
  AND intended.pipeline_id IS NULL;

-- Restore approval and conditions for an edge present in the latest
-- tenant-owned transition snapshot.
UPDATE sales_pipeline_transitions current_transition
INNER JOIN sales_pipeline_stages source_stage
    ON source_stage.id=current_transition.from_stage_id
    AND source_stage.pipeline_id=current_transition.pipeline_id
    AND source_stage.organization_id=current_transition.organization_id
INNER JOIN sales_pipeline_stages target_stage
    ON target_stage.id=current_transition.to_stage_id
    AND target_stage.pipeline_id=current_transition.pipeline_id
    AND target_stage.organization_id=current_transition.organization_id
INNER JOIN tmp_sales_transition_revision_policy intended
    ON intended.organization_id=current_transition.organization_id
    AND intended.pipeline_id=current_transition.pipeline_id
    AND intended.from_stage_id=current_transition.from_stage_id
    AND intended.to_stage_id=current_transition.to_stage_id
SET current_transition.requires_approval=intended.requires_approval,
    current_transition.conditions=intended.conditions
WHERE source_stage.code='NEW'
  AND target_stage.code='QUALIFIED';

-- Recreate NEW -> QUALIFIED if the latest tenant snapshot contained it but the
-- edge is currently missing.
INSERT INTO sales_pipeline_transitions (
    id,
    organization_id,
    pipeline_id,
    from_stage_id,
    to_stage_id,
    requires_approval,
    conditions
)
SELECT
    LEFT(SHA2(CONCAT(pipeline.id, ':lead:new:qualified:restored'), 256), 32),
    pipeline.organization_id,
    pipeline.id,
    source_stage.id,
    target_stage.id,
    intended.requires_approval,
    intended.conditions
FROM sales_pipelines pipeline
INNER JOIN sales_pipeline_stages source_stage
    ON source_stage.pipeline_id=pipeline.id
    AND source_stage.organization_id=pipeline.organization_id
    AND source_stage.code='NEW'
    AND source_stage.status='ACTIVE'
INNER JOIN sales_pipeline_stages target_stage
    ON target_stage.pipeline_id=pipeline.id
    AND target_stage.organization_id=pipeline.organization_id
    AND target_stage.code='QUALIFIED'
    AND target_stage.status='ACTIVE'
INNER JOIN tmp_sales_transition_revision_policy intended
    ON intended.organization_id=pipeline.organization_id
    AND intended.pipeline_id=pipeline.id
    AND intended.from_stage_id=source_stage.id
    AND intended.to_stage_id=target_stage.id
WHERE pipeline.status='ACTIVE'
  AND NOT EXISTS (
      SELECT 1
      FROM sales_pipeline_transitions existing_transition
      WHERE existing_transition.pipeline_id=pipeline.id
        AND existing_transition.from_stage_id=source_stage.id
        AND existing_transition.to_stage_id=target_stage.id
  );

-- Pipelines with no transition revision history have no tenant-owned policy to
-- recover. For those only, retain the canonical direct-qualification behavior.
INSERT INTO sales_pipeline_transitions (
    id,
    organization_id,
    pipeline_id,
    from_stage_id,
    to_stage_id,
    requires_approval,
    conditions
)
SELECT
    LEFT(SHA2(CONCAT(pipeline.id, ':lead:new:qualified'), 256), 32),
    pipeline.organization_id,
    pipeline.id,
    source_stage.id,
    target_stage.id,
    0,
    JSON_ARRAY()
FROM sales_pipelines pipeline
INNER JOIN sales_pipeline_stages source_stage
    ON source_stage.pipeline_id=pipeline.id
    AND source_stage.organization_id=pipeline.organization_id
    AND source_stage.code='NEW'
    AND source_stage.status='ACTIVE'
INNER JOIN sales_pipeline_stages target_stage
    ON target_stage.pipeline_id=pipeline.id
    AND target_stage.organization_id=pipeline.organization_id
    AND target_stage.code='QUALIFIED'
    AND target_stage.status='ACTIVE'
LEFT JOIN tmp_sales_latest_transition_revisions latest
    ON latest.organization_id=pipeline.organization_id
    AND latest.pipeline_id=pipeline.id
WHERE pipeline.status='ACTIVE'
  AND latest.pipeline_id IS NULL
  AND NOT EXISTS (
      SELECT 1
      FROM sales_pipeline_transitions existing_transition
      WHERE existing_transition.pipeline_id=pipeline.id
        AND existing_transition.from_stage_id=source_stage.id
        AND existing_transition.to_stage_id=target_stage.id
  );

DROP TEMPORARY TABLE tmp_sales_transition_revision_policy;
DROP TEMPORARY TABLE tmp_sales_latest_transition_revisions;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261003_000123_sales_lead_qualification_governance_repair');
