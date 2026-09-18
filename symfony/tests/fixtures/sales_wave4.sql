-- Symfony Sales Wave 4 Agent -> Tool -> Sales fixtures.
-- Extends Wave 3 with the persistence surfaces used by the canonical Agent Runtime.
-- The actual sales.call.completed event is inserted by the Wave 4 CI step after
-- Waves 1-3 have completed so the agent path cannot interfere with earlier slices.

ALTER TABLE cos_decisions
    ADD COLUMN organization_id VARCHAR(190) NULL AFTER id,
    ADD COLUMN type VARCHAR(160) NULL AFTER organization_id,
    ADD COLUMN source_type VARCHAR(32) NULL AFTER type,
    MODIFY COLUMN source_id VARCHAR(100) NULL,
    ADD COLUMN subject_type VARCHAR(100) NULL AFTER source_id,
    ADD COLUMN subject_id VARCHAR(100) NULL AFTER subject_type,
    ADD COLUMN decision VARCHAR(160) NULL AFTER subject_id,
    MODIFY COLUMN reason TEXT NULL,
    ADD COLUMN evidence JSON NULL AFTER confidence,
    ADD COLUMN context_reference JSON NULL AFTER evidence,
    ADD COLUMN correlation_id VARCHAR(128) NULL AFTER context_reference,
    ADD COLUMN created_at DATETIME(6) NULL AFTER correlation_id,
    ADD KEY idx_wave4_decision_source (organization_id,source_type,source_id),
    ADD KEY idx_wave4_decision_correlation (organization_id,correlation_id);

CREATE TABLE cos_agent_runs (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    agent_name VARCHAR(160) NOT NULL,
    agent_version VARCHAR(40) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    model VARCHAR(160) NOT NULL,
    prompt_version VARCHAR(40) NOT NULL,
    schema_version VARCHAR(40) NOT NULL,
    subject_type VARCHAR(100) NULL,
    subject_id VARCHAR(100) NULL,
    status VARCHAR(32) NOT NULL,
    context_reference JSON NULL,
    input_snapshot JSON NULL,
    input_redacted TINYINT(1) NOT NULL DEFAULT 1,
    input_expires_at DATETIME(6) NULL,
    output JSON NULL,
    confidence DECIMAL(5,4) NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cost_amount DECIMAL(14,6) NULL,
    cost_currency VARCHAR(16) NULL,
    duration_ms INT UNSIGNED NULL,
    error TEXT NULL,
    correlation_id VARCHAR(128) NOT NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_wave4_agent_org (organization_id,agent_name,created_at),
    KEY idx_wave4_agent_corr (organization_id,correlation_id)
);

CREATE TABLE cos_agent_configurations (
    organization_id VARCHAR(190) NOT NULL,
    domain_name VARCHAR(80) NOT NULL,
    agent_name VARCHAR(160) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    profile VARCHAR(80) NOT NULL DEFAULT 'default',
    model VARCHAR(160) NULL,
    business_instructions TEXT NULL,
    context_sources JSON NOT NULL,
    allowed_actions JSON NOT NULL,
    confidence_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    PRIMARY KEY (organization_id,domain_name,agent_name)
);

INSERT INTO cos_agent_configurations
    (organization_id,domain_name,agent_name,enabled,profile,model,business_instructions,context_sources,allowed_actions,confidence_threshold)
VALUES
(
    'default','sales','sales_intelligence',1,'balanced',NULL,'',
    JSON_ARRAY(
        'deal','person','lead','pipeline','activities','communications','last_contact','next_action',
        'sales_history','assigned_manager','product_or_property','metrics','rules','policies','goals'
    ),
    JSON_ARRAY(
        'sales.create_task','sales.create_followup','sales.schedule_followup','sales.send_message',
        'sales.change_stage','sales.request_document','sales.schedule_meeting','sales.request_manager_review'
    ),
    0.6500
);

CREATE TABLE cos_agent_trace_events (
    run_id VARCHAR(64) NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    organization_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(40) NOT NULL,
    duration_ms INT UNSIGNED NULL,
    cost_amount DECIMAL(14,6) NULL,
    cost_unit VARCHAR(16) NULL,
    error TEXT NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (run_id,sequence),
    KEY idx_wave4_trace_org_time (organization_id,occurred_at),
    CONSTRAINT fk_wave4_trace_run FOREIGN KEY (run_id) REFERENCES cos_agent_runs(id) ON DELETE CASCADE
);

INSERT INTO cos_rules
    (id,organization_id,code,name,trigger_type,conditions,effect,version,priority,status,created_by_type,created_by_id)
VALUES
(
    'rule-call-analysis-v1',
    'default',
    'rule-call-analysis-v1',
    'Analyze completed sales calls',
    'sales.call.completed',
    JSON_ARRAY(),
    JSON_OBJECT(
        'type','CREATE_ACTION',
        'action_type','agent.run.sales_intelligence',
        'target_type','{{event.aggregate_type}}',
        'target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT(
            'question','Analyze the completed call and recommend the safest high-value next sales action.'
        ),
        'execution_mode','AUTO',
        'risk_level','LOW'
    ),
    1,10,'ACTIVE','SYSTEM','wave4-fixture'
);
