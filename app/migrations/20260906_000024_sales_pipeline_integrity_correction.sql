-- Corrective migration: never reassign a valid non-default pipeline.
UPDATE tn_client_cases c
INNER JOIN sales_pipeline_stages s ON s.id=c.stage_id
INNER JOIN sales_pipelines p ON p.id=s.pipeline_id AND p.organization_id=c.organization_id
SET c.pipeline_id=s.pipeline_id,
    c.stage=s.code,
    c.probability=COALESCE(c.probability,s.probability_default)
WHERE c.stage_id IS NOT NULL
  AND (c.pipeline_id IS NULL OR c.pipeline_id<>s.pipeline_id OR c.stage<>s.code);

UPDATE tn_client_cases c
INNER JOIN sales_pipelines p ON p.id=c.pipeline_id AND p.organization_id=c.organization_id AND p.status='ACTIVE'
INNER JOIN sales_pipeline_stages s ON s.pipeline_id=p.id AND s.code=CASE UPPER(c.stage)
    WHEN 'NEW' THEN 'NEW' WHEN 'QUALIFICATION' THEN 'QUALIFIED' WHEN 'QUALIFIED' THEN 'QUALIFIED'
    WHEN 'NEED_DEFINED' THEN 'QUALIFIED' WHEN 'MATCHING' THEN 'PROPOSAL' WHEN 'PROPOSAL' THEN 'PROPOSAL'
    WHEN 'VIEWING' THEN 'MEETING' WHEN 'MEETING' THEN 'MEETING' WHEN 'NEGOTIATION' THEN 'NEGOTIATION'
    WHEN 'DEAL' THEN 'WON' WHEN 'AFTERCARE' THEN 'WON' WHEN 'WON' THEN 'WON' WHEN 'LOST' THEN 'LOST'
    ELSE 'CONTACTED' END
SET c.stage_id=s.id,c.stage=s.code,c.probability=COALESCE(c.probability,s.probability_default)
WHERE c.pipeline_id IS NOT NULL AND c.stage_id IS NULL;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260906_000024_sales_pipeline_integrity_correction');
