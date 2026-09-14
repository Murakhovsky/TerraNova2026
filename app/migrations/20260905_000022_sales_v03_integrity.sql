CREATE TABLE IF NOT EXISTS sales_operation_receipts (
    organization_id VARCHAR(40) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    mutation_id VARCHAR(100) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, operation_type, idempotency_key),
    CONSTRAINT fk_sales_operation_receipt_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE tn_client_cases c
INNER JOIN sales_pipelines p ON p.organization_id=c.organization_id AND p.is_default=1 AND p.status='ACTIVE'
INNER JOIN sales_pipeline_stages s ON s.pipeline_id=p.id AND s.code=CASE c.stage
    WHEN 'new' THEN 'NEW' WHEN 'qualification' THEN 'QUALIFIED' WHEN 'need_defined' THEN 'QUALIFIED'
    WHEN 'matching' THEN 'PROPOSAL' WHEN 'viewing' THEN 'MEETING' WHEN 'negotiation' THEN 'NEGOTIATION'
    WHEN 'deal' THEN 'WON' WHEN 'aftercare' THEN 'WON' WHEN 'lost' THEN 'LOST' ELSE 'CONTACTED' END
SET c.pipeline_id=p.id,c.stage_id=s.id,c.probability=COALESCE(c.probability,s.probability_default)
WHERE c.pipeline_id IS NULL AND c.stage_id IS NULL;

UPDATE tn_client_cases c
INNER JOIN sales_pipelines p ON p.id=c.pipeline_id AND p.organization_id=c.organization_id AND p.status='ACTIVE'
INNER JOIN sales_pipeline_stages s ON s.pipeline_id=p.id AND s.code=CASE c.stage
    WHEN 'new' THEN 'NEW' WHEN 'qualification' THEN 'QUALIFIED' WHEN 'need_defined' THEN 'QUALIFIED'
    WHEN 'matching' THEN 'PROPOSAL' WHEN 'viewing' THEN 'MEETING' WHEN 'negotiation' THEN 'NEGOTIATION'
    WHEN 'deal' THEN 'WON' WHEN 'aftercare' THEN 'WON' WHEN 'lost' THEN 'LOST' ELSE 'CONTACTED' END
SET c.stage_id=s.id,c.probability=COALESCE(c.probability,s.probability_default)
WHERE c.pipeline_id IS NOT NULL AND c.stage_id IS NULL;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260905_000022_sales_v03_integrity');
