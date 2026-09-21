ALTER TABLE cos_audit_log
    ADD COLUMN source_type ENUM('HUMAN','AGENT','TOOL','WORKFLOW','INTEGRATION','WORKER','SYSTEM')
        NOT NULL DEFAULT 'SYSTEM' AFTER actor_id,
    ADD KEY idx_cos_audit_org_source (organization_id, source_type, created_at);

UPDATE cos_audit_log
SET source_type = CASE actor_type
    WHEN 'USER' THEN 'HUMAN'
    WHEN 'AGENT' THEN 'AGENT'
    WHEN 'INTEGRATION' THEN 'INTEGRATION'
    WHEN 'WORKER' THEN 'WORKER'
    ELSE 'SYSTEM'
END;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260921_000066_platform_audit_history');
