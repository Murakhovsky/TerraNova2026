ALTER TABLE tn_client_cases MODIFY stage VARCHAR(100) NOT NULL DEFAULT 'NEW';

UPDATE tn_client_cases c
INNER JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.pipeline_id=c.pipeline_id AND s.organization_id=c.organization_id
SET c.stage=s.code
WHERE c.stage<>s.code;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260905_000023_sales_stage_compatibility');
