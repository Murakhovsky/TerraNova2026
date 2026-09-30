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
    KEY idx_wave3_history_open (organization_id, deal_id, left_at),
    UNIQUE KEY uq_wave3_history_event (organization_id, source_event_id)
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


-- Kernel deterministic runtime tables required by Symfony Wave 3.
ALTER TABLE cos_actions
    MODIFY COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ADD COLUMN source_type VARCHAR(32) NOT NULL DEFAULT 'SYSTEM' AFTER parameters,
    ADD COLUMN execution_mode VARCHAR(32) NOT NULL DEFAULT 'MANUAL' AFTER status,
    ADD COLUMN idempotency_key VARCHAR(191) NULL AFTER risk_level,
    ADD COLUMN available_at DATETIME(6) NULL AFTER correlation_id,
    ADD COLUMN started_at DATETIME(6) NULL AFTER available_at,
    ADD COLUMN executed_at DATETIME(6) NULL AFTER started_at,
    ADD COLUMN failed_at DATETIME(6) NULL AFTER executed_at,
    ADD COLUMN last_error TEXT NULL AFTER failed_at,
    ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER created_at,
    ADD UNIQUE KEY uq_wave3_actions_idempotency (organization_id,idempotency_key);

CREATE TABLE cos_action_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action_id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(190) NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    worker_id VARCHAR(100) NULL,
    status VARCHAR(32) NOT NULL,
    result JSON NULL,
    error TEXT NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    UNIQUE KEY uq_wave3_action_attempt (action_id,attempt)
);

CREATE TABLE cos_rules (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    code VARCHAR(160) NOT NULL,
    name VARCHAR(220) NOT NULL,
    trigger_type VARCHAR(160) NOT NULL,
    conditions JSON NOT NULL,
    effect JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 100,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_by_type VARCHAR(32) NOT NULL DEFAULT 'SYSTEM',
    created_by_id VARCHAR(100) NOT NULL DEFAULT 'wave3-fixture',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave3_rule_code (organization_id,code,version)
);

CREATE TABLE cos_rule_evaluations (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    rule_id VARCHAR(64) NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    matched TINYINT(1) NOT NULL,
    context_snapshot JSON NULL,
    evaluation_details JSON NULL,
    correlation_id VARCHAR(128) NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_wave3_rule_eval (rule_id,event_id)
);

CREATE TABLE cos_policies (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    code VARCHAR(160) NOT NULL,
    name VARCHAR(220) NOT NULL,
    action_type VARCHAR(160) NOT NULL,
    conditions JSON NOT NULL,
    decision VARCHAR(32) NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave3_policy_code (organization_id,code,version)
);

CREATE TABLE cos_policy_evaluations (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    action_id VARCHAR(64) NOT NULL,
    policy_id VARCHAR(64) NULL,
    decision VARCHAR(32) NOT NULL,
    reason TEXT NULL,
    context_snapshot JSON NULL,
    correlation_id VARCHAR(128) NOT NULL,
    evaluated_at DATETIME(6) NOT NULL
);

CREATE TABLE cos_approvals (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    action_id VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    approver_type VARCHAR(32) NOT NULL,
    approver_id VARCHAR(100) NOT NULL,
    requested_by_type VARCHAR(32) NOT NULL,
    requested_by_id VARCHAR(100) NOT NULL,
    decided_by_type VARCHAR(32) NULL,
    decided_by_id VARCHAR(100) NULL,
    reason TEXT NULL,
    decision_note TEXT NULL,
    expires_at DATETIME(6) NULL,
    decided_at DATETIME(6) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE cos_audit_log (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    category VARCHAR(100) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id VARCHAR(100) NOT NULL,
    source_type VARCHAR(32) NOT NULL DEFAULT 'SYSTEM',
    subject_type VARCHAR(100) NOT NULL,
    subject_id VARCHAR(100) NOT NULL,
    action VARCHAR(160) NULL,
    reason TEXT NULL,
    input_references JSON NULL,
    changes JSON NULL,
    result JSON NULL,
    metadata JSON NULL,
    correlation_id VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL
);

CREATE TABLE cos_event_consumptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(190) NOT NULL,
    consumer_name VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PROCESSING',
    attempts INT UNSIGNED NOT NULL DEFAULT 1,
    started_at DATETIME(6) NOT NULL,
    processed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_wave3_event_consumer (event_id,consumer_name)
);

CREATE TABLE cos_jobs (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    type VARCHAR(160) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    completed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    idempotency_key VARCHAR(191) NULL,
    correlation_id VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_wave3_jobs_idempotency (organization_id,idempotency_key)
);

CREATE TABLE cos_tenant_execution_leases (
    lease_id VARCHAR(191) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL
);

CREATE TABLE cos_organization_modules (
    organization_id VARCHAR(190) NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    configuration_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id,module_id)
);

CREATE TABLE cos_module_installations (
    organization_id VARCHAR(190) NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'INSTALLED',
    installed_version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(32) NOT NULL,
    installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id,module_id)
);

CREATE TABLE cos_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    capability VARCHAR(32) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    config JSON NULL,
    UNIQUE KEY uq_wave3_integration (organization_id,capability,provider)
);

INSERT INTO cos_integrations(organization_id,capability,provider,name,status,is_primary,config) VALUES
    ('default','CRM','aida','AIDA native CRM','ACTIVE',1,JSON_OBJECT('mode','native')),
    ('other-org','CRM','aida','AIDA native CRM','ACTIVE',1,JSON_OBJECT('mode','native'));

CREATE TABLE sales_deal_owner_history (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    deal_id VARCHAR(100) NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME(6) NULL,
    unassigned_at DATETIME(6) NULL,
    source_event_id VARCHAR(64) NULL,
    correlation_id VARCHAR(128) NULL,
    history_quality VARCHAR(32) NOT NULL,
    projected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_wave3_owner_event (organization_id,source_event_id)
);

INSERT INTO sales_deal_owner_history
    (id,organization_id,deal_id,owner_user_id,assigned_at,unassigned_at,source_event_id,correlation_id,history_quality)
VALUES
    ('wave3-owner-101','default','101',1001,NOW(6)-INTERVAL 3 DAY,NULL,'wave3-owner-seed-101','wave3-seed','COMPLETE');

UPDATE tn_client_cases
SET status='active',
    stage_id=10,
    stage='new',
    assigned_user_id=1001,
    last_activity_at=NOW()-INTERVAL 3 DAY,
    next_contact_at=NOW()-INTERVAL 2 HOUR
WHERE organization_id='default' AND id=101;

INSERT INTO tn_client_case_activities
    (organization_id,client_case_id,person_id,user_id,activity_type,title,body,due_at,completed_at)
SELECT 'default',101,person_id,assigned_user_id,'followup','Wave 3 overdue follow-up','Seeded overdue follow-up',NOW()-INTERVAL 2 HOUR,NULL
FROM tn_client_cases WHERE organization_id='default' AND id=101;

INSERT INTO cos_rules
    (id,organization_id,code,name,trigger_type,conditions,effect,version,priority,status,created_by_type,created_by_id)
VALUES
(
    'sales-new-lead-followup-v1','default','sales-new-lead-followup-v1','New Lead follow-up',
    'sales.lead.created',
    JSON_ARRAY(
        JSON_OBJECT('field','lead.status','operator','=','value','new'),
        JSON_OBJECT('field','lead.owner_id','operator','IS_NOT_NULL')
    ),
    JSON_OBJECT(
        'type','CREATE_ACTION','action_type','sales.create_lead_followup_task',
        'target_type','lead','target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT('title','First contact with new Lead','due_in_minutes',30),
        'execution_mode','AUTO','risk_level','LOW'
    ),1,5,'ACTIVE','SYSTEM','wave3-fixture'
),
(
    'sales-overdue-followup-escalation-v1','default','sales-overdue-followup-escalation-v1','Overdue follow-up escalation',
    'sales.followup.overdue',
    JSON_ARRAY(
        JSON_OBJECT('field','deal.status','operator','=','value','active'),
        JSON_OBJECT('field','activity.completed_at','operator','IS_NULL'),
        JSON_OBJECT('field','activity.is_overdue','operator','=','value',true)
    ),
    JSON_OBJECT(
        'type','CREATE_ACTION','action_type','sales.escalate_overdue_followup',
        'target_type','deal','target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT('title','Urgent overdue follow-up','due_in_minutes',60),
        'execution_mode','AUTO','risk_level','MEDIUM'
    ),1,20,'ACTIVE','SYSTEM','wave3-fixture'
),
(
    'sales-no-activity-48h-v1','default','sales-no-activity-48h-v1','No activity 48h',
    'sales.no_activity_detected',
    JSON_ARRAY(JSON_OBJECT('field','deal.no_activity_48h','operator','=','value',true)),
    JSON_OBJECT(
        'type','CREATE_ACTION','action_type','sales.create_followup',
        'target_type','deal','target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT('title','Re-engage customer','due_in_minutes',60),
        'execution_mode','AUTO','risk_level','MEDIUM'
    ),1,25,'ACTIVE','SYSTEM','wave3-fixture'
),
(
    'sales-deal-stuck-v1','default','sales-deal-stuck-v1','Stuck Deal review',
    'sales.deal.stuck',
    JSON_ARRAY(JSON_OBJECT('field','deal.stuck_in_stage','operator','=','value',true)),
    JSON_OBJECT(
        'type','CREATE_ACTION','action_type','sales.request_manager_review',
        'target_type','deal','target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT('title','Review stuck Deal','due_in_minutes',240),
        'execution_mode','AUTO','risk_level','MEDIUM'
    ),1,30,'ACTIVE','SYSTEM','wave3-fixture'
),
(
    'sales-new-deal-qualification-v1','default','sales-new-deal-qualification-v1','New Deal qualification',
    'sales.deal.created',
    JSON_ARRAY(
        JSON_OBJECT('field','deal.status','operator','=','value','active'),
        JSON_OBJECT('field','deal.stage_code','operator','=','value','NEW')
    ),
    JSON_OBJECT(
        'type','CREATE_ACTION','action_type','sales.create_qualification_task',
        'target_type','deal','target_id','{{event.aggregate_id}}',
        'parameters',JSON_OBJECT('title','Qualify new request','due_in_minutes',240),
        'execution_mode','AUTO','risk_level','LOW'
    ),1,10,'ACTIVE','SYSTEM','wave3-fixture'
);

INSERT INTO cos_policies
    (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
VALUES
    ('sales-auto-lead-followup-task-v1','default','sales-auto-lead-followup-task-v1','Auto lead follow-up','sales.create_lead_followup_task',JSON_ARRAY(),'AUTO',10,1,'ACTIVE'),
    ('sales-auto-overdue-escalation-v1','default','sales-auto-overdue-escalation-v1','Auto overdue escalation','sales.escalate_overdue_followup',JSON_ARRAY(),'AUTO',10,1,'ACTIVE'),
    ('policy-create-followup-auto-v1','default','policy-create-followup-auto-v1','Auto follow-up','sales.create_followup',JSON_ARRAY(),'AUTO',10,1,'ACTIVE'),
    ('policy-manager-review-auto-v1','default','policy-manager-review-auto-v1','Auto manager review','sales.request_manager_review',JSON_ARRAY(),'AUTO',10,1,'ACTIVE'),
    ('sales-auto-qualification-task-v1','default','sales-auto-qualification-task-v1','Auto qualification','sales.create_qualification_task',JSON_ARRAY(),'AUTO',10,1,'ACTIVE');
