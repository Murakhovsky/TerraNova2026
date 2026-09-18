-- Symfony Sales Wave 1 isolated legacy fixtures.
-- Extends the deliberately minimal Symfony CI legacy schema with the read-side
-- columns/tables required by the canonical SalesWorkspaceReadModel.

ALTER TABLE sales_pipelines
    ADD COLUMN name VARCHAR(160) NOT NULL DEFAULT 'Pipeline' AFTER organization_id;

ALTER TABLE sales_pipeline_stages
    ADD COLUMN name VARCHAR(160) NOT NULL DEFAULT 'Stage' AFTER code,
    ADD COLUMN probability_default DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER sort_order,
    ADD COLUMN is_won TINYINT(1) NOT NULL DEFAULT 0 AFTER probability_default;

UPDATE sales_pipelines SET name='Default Sales' WHERE id=1;
UPDATE sales_pipelines SET name='Other Sales' WHERE id=2;
UPDATE sales_pipeline_stages SET name='New', probability_default=20, is_won=0 WHERE id IN (10,20);
UPDATE sales_pipeline_stages SET name='Won', probability_default=100, is_won=1 WHERE id=11;

CREATE TABLE tn_people (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    telegram VARCHAR(255) NULL
);

INSERT INTO tn_people (id, organization_id, full_name, phone, email, telegram) VALUES
    (1, 'default', 'Default Customer', '+380000000001', 'customer@example.test', '@customer'),
    (2, 'default', 'Second Customer', '+380000000002', 'second@example.test', NULL),
    (3, 'other-org', 'Other Customer', '+380000000003', 'other-customer@example.test', NULL);

ALTER TABLE tn_client_cases
    ADD COLUMN public_id VARCHAR(64) NULL AFTER organization_id,
    ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER public_id,
    ADD COLUMN title VARCHAR(255) NULL AFTER person_id,
    ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'active' AFTER title,
    ADD COLUMN priority VARCHAR(32) NOT NULL DEFAULT 'normal' AFTER status,
    ADD COLUMN pipeline_id BIGINT UNSIGNED NULL AFTER priority,
    ADD COLUMN assigned_user_id BIGINT UNSIGNED NULL AFTER stage_id,
    ADD COLUMN deal_value DECIMAL(14,2) NULL AFTER assigned_user_id,
    ADD COLUMN budget_max DECIMAL(14,2) NULL AFTER deal_value,
    ADD COLUMN currency VARCHAR(8) NOT NULL DEFAULT 'USD' AFTER budget_max,
    ADD COLUMN probability DECIMAL(5,2) NULL AFTER currency,
    ADD COLUMN last_activity_at DATETIME NULL AFTER probability,
    ADD COLUMN next_contact_at DATETIME NULL AFTER last_activity_at,
    ADD COLUMN expected_close_at DATETIME NULL AFTER next_contact_at,
    ADD COLUMN closed_at DATETIME NULL AFTER expected_close_at,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER closed_at,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;

UPDATE tn_client_cases SET
    public_id='CASE-101', person_id=1, title='Default Opportunity', status='active', priority='high',
    pipeline_id=1, assigned_user_id=1001, deal_value=50000, budget_max=55000, currency='USD',
    probability=35, last_activity_at=NOW()-INTERVAL 3 DAY, next_contact_at=NOW()-INTERVAL 2 HOUR,
    expected_close_at=NOW()+INTERVAL 14 DAY, created_at=NOW()-INTERVAL 10 DAY, updated_at=NOW()
WHERE id=101;

UPDATE tn_client_cases SET
    public_id='CASE-102', person_id=2, title='Second Opportunity', status='active', priority='normal',
    pipeline_id=1, assigned_user_id=1001, deal_value=80000, budget_max=85000, currency='USD',
    probability=100, last_activity_at=NOW(), next_contact_at=NOW()+INTERVAL 1 DAY,
    expected_close_at=NOW()+INTERVAL 3 DAY, created_at=NOW()-INTERVAL 20 DAY, updated_at=NOW()
WHERE id=102;

UPDATE tn_client_cases SET
    public_id='CASE-201', person_id=3, title='Other Opportunity', status='active', priority='normal',
    pipeline_id=2, assigned_user_id=1003, deal_value=120000, budget_max=125000, currency='USD',
    probability=20, last_activity_at=NOW(), next_contact_at=NOW()+INTERVAL 1 DAY,
    expected_close_at=NOW()+INTERVAL 20 DAY, created_at=NOW()-INTERVAL 5 DAY, updated_at=NOW()
WHERE id=201;

CREATE TABLE tn_leads (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    person_id BIGINT UNSIGNED NULL,
    client_case_id BIGINT UNSIGNED NULL,
    property_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(160) NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'buyer',
    deal_type VARCHAR(32) NOT NULL DEFAULT 'sale',
    message TEXT NULL,
    preferred_contact VARCHAR(32) NOT NULL DEFAULT 'any',
    source_page VARCHAR(255) NULL,
    request_intent VARCHAR(64) NULL,
    manager_note TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    assigned_user_id BIGINT UNSIGNED NULL,
    last_contacted_at DATETIME NULL,
    next_contact_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_leads (
    id,organization_id,person_id,client_case_id,full_name,phone,email,role,deal_type,message,
    preferred_contact,source_page,request_intent,manager_note,status,assigned_user_id,last_contacted_at,next_contact_at,created_at,updated_at
) VALUES
    (301,'default',1,101,'Default Lead','+380000000001','lead@example.test','buyer','sale','Interested in property','phone','landing-a','viewing','Call after lunch','new',1001,NULL,NOW()-INTERVAL 1 HOUR,NOW()-INTERVAL 2 DAY,NOW()),
    (302,'default',2,102,'Second Lead','+380000000002','lead2@example.test','buyer','sale','Second request','email','landing-b','general_contact',NULL,'contacted',1001,NOW(),NOW()+INTERVAL 1 DAY,NOW()-INTERVAL 1 DAY,NOW()),
    (401,'other-org',3,201,'Other Lead','+380000000003','other-lead@example.test','buyer','sale','Other tenant','any','landing-other','general_contact',NULL,'new',1003,NULL,NULL,NOW(),NOW());

CREATE TABLE tn_lead_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lead_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type VARCHAR(32) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_lead_activities (lead_id,user_id,activity_type,title,body,created_at) VALUES
    (301,1001,'note','Initial review','Lead reviewed by manager',NOW());

CREATE TABLE tn_client_case_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    client_case_id BIGINT UNSIGNED NOT NULL,
    activity_type VARCHAR(32) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_client_case_activities (organization_id,client_case_id,activity_type,title,body,due_at,created_at) VALUES
    ('default',101,'followup','Follow up','Call customer',NOW()-INTERVAL 1 HOUR,NOW()-INTERVAL 1 DAY),
    ('default',102,'meeting','Meeting','Demo meeting',NOW()+INTERVAL 4 HOUR,NOW());

CREATE TABLE sales_communications (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    deal_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(32) NOT NULL,
    direction VARCHAR(32) NOT NULL,
    sender VARCHAR(255) NULL,
    recipient VARCHAR(255) NULL,
    body TEXT NULL,
    occurred_at DATETIME NOT NULL
);

INSERT INTO sales_communications (id,organization_id,deal_id,channel,direction,sender,recipient,body,occurred_at) VALUES
    ('comm-1','default',101,'email','INBOUND','customer@example.test','manager@example.test','Can we schedule a viewing?',NOW()-INTERVAL 30 MINUTE);

CREATE TABLE cos_events (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    aggregate_type VARCHAR(64) NOT NULL,
    aggregate_id VARCHAR(64) NOT NULL,
    type VARCHAR(160) NOT NULL,
    payload JSON NULL,
    occurred_at DATETIME NOT NULL,
    correlation_id VARCHAR(128) NULL
);

INSERT INTO cos_events (id,organization_id,aggregate_type,aggregate_id,type,payload,occurred_at,correlation_id) VALUES
    ('event-1','default','deal','101','sales.test',JSON_OBJECT('fixture',true),NOW()-INTERVAL 20 MINUTE,'corr-wave1');

CREATE TABLE cos_actions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    type VARCHAR(160) NOT NULL,
    target_type VARCHAR(64) NOT NULL,
    target_id VARCHAR(64) NOT NULL,
    status VARCHAR(64) NOT NULL,
    risk_level VARCHAR(32) NULL,
    parameters JSON NULL,
    source_id VARCHAR(64) NULL,
    correlation_id VARCHAR(128) NULL,
    created_at DATETIME NOT NULL
);

INSERT INTO cos_actions (id,organization_id,type,target_type,target_id,status,risk_level,parameters,source_id,correlation_id,created_at) VALUES
    ('action-1','default','sales.followup','deal','101','PROPOSED','LOW',JSON_OBJECT('fixture',true),'source-1','corr-wave1',NOW()-INTERVAL 10 MINUTE);

CREATE TABLE cos_decisions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    source_id VARCHAR(64) NULL,
    reason VARCHAR(255) NULL,
    confidence DECIMAL(5,4) NULL
);
INSERT INTO cos_decisions (id,source_id,reason,confidence) VALUES ('decision-1','source-1','Fixture recommendation',0.9000);

CREATE TABLE cos_action_outcomes (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    measured_at DATETIME NOT NULL
);
INSERT INTO cos_action_outcomes (id,organization_id,measured_at) VALUES ('outcome-1','default',NOW());
