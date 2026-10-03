-- Deterministic functional-acceptance data for the disposable CI runtime.
-- Must never be applied to a shared or production database.
SET @e2e_user_id := (
    SELECT id FROM tn_users
    WHERE organization_id='default' AND email=@e2e_email
    LIMIT 1
);

INSERT INTO cos_organization_modules (organization_id,module_id,enabled)
VALUES ('default','sales',1)
ON DUPLICATE KEY UPDATE enabled=1,updated_at=CURRENT_TIMESTAMP;

INSERT INTO cos_module_installations
    (organization_id,module_id,status,installed_version,schema_version)
VALUES ('default','sales','INSTALLED','1.0.0','0.8.6')
ON DUPLICATE KEY UPDATE
    status='INSTALLED',installed_version='1.0.0',schema_version='0.8.6',updated_at=CURRENT_TIMESTAMP;

INSERT INTO tn_people (organization_id,public_id,full_name,phone,email,notes)
VALUES ('default','PN-UI-ACCEPT','UI Acceptance Customer','+380000009999','ui-acceptance-customer@example.test','Disposable CI fixture')
ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),phone=VALUES(phone),email=VALUES(email);

SET @e2e_person_id := (
    SELECT id FROM tn_people WHERE organization_id='default' AND public_id='PN-UI-ACCEPT' LIMIT 1
);

INSERT INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
VALUES
    ('default',@e2e_user_id,'sales.deal.assign','ACTIVE','ui-functional-acceptance',NOW(6),NOW(6)),
    ('default',@e2e_user_id,'sales.approval.decide','ACTIVE','ui-functional-acceptance',NOW(6),NOW(6)),
    ('default',@e2e_user_id,'sales.approval.any_team','ACTIVE','ui-functional-acceptance',NOW(6),NOW(6))
ON DUPLICATE KEY UPDATE status='ACTIVE',granted_by=VALUES(granted_by),updated_at=NOW(6);
SET @e2e_pipeline_id := (
    SELECT id FROM sales_pipelines
    WHERE organization_id='default' AND code='default-sales' AND status='ACTIVE'
    ORDER BY is_default DESC LIMIT 1
);
SET @e2e_stage_new := (
    SELECT id FROM sales_pipeline_stages
    WHERE organization_id='default' AND pipeline_id=@e2e_pipeline_id AND code='NEW' LIMIT 1
);

INSERT INTO tn_client_cases
    (organization_id,public_id,person_id,type,title,status,stage,priority,assigned_user_id,source,
     budget_min,budget_max,currency,description,started_at,next_contact_at,pipeline_id,stage_id,
     deal_value,probability,last_activity_at,expected_close_at)
VALUES
    ('default','CC-UI-ACCEPT',@e2e_person_id,'buy','UI Acceptance Deal','active','new','high',@e2e_user_id,'ui-acceptance',
     100000,150000,'USD','Disposable browser acceptance deal.',NOW()-INTERVAL 2 DAY,NOW()-INTERVAL 1 HOUR,
     @e2e_pipeline_id,@e2e_stage_new,125000,15,NOW()-INTERVAL 1 DAY,NOW()+INTERVAL 14 DAY)
ON DUPLICATE KEY UPDATE
    person_id=VALUES(person_id),status='active',stage='new',priority='high',assigned_user_id=@e2e_user_id,
    pipeline_id=@e2e_pipeline_id,stage_id=@e2e_stage_new,last_activity_at=NOW()-INTERVAL 1 DAY,
    next_contact_at=NOW()-INTERVAL 1 HOUR,expected_close_at=NOW()+INTERVAL 14 DAY;

SET @e2e_deal_id := (
    SELECT id FROM tn_client_cases
    WHERE organization_id='default' AND public_id='CC-UI-ACCEPT'
    LIMIT 1
);

INSERT INTO tn_leads
    (organization_id,person_id,client_case_id,full_name,phone,email,role,deal_type,message,
     preferred_contact,source_page,status,assigned_user_id,manager_note,last_contacted_at,next_contact_at)
VALUES
    ('default',@e2e_person_id,@e2e_deal_id,'UI Acceptance Lead','+380000009999','ui-acceptance-lead@example.test',
     'buyer','sale','Browser functional acceptance lead','email','ui-acceptance','new',@e2e_user_id,
     'Disposable CI fixture',NULL,NOW()+INTERVAL 1 DAY);

DELETE FROM tn_client_case_activities
WHERE organization_id='default' AND client_case_id=@e2e_deal_id AND title='UI Acceptance Task';

INSERT INTO tn_client_case_activities
    (organization_id,client_case_id,person_id,user_id,activity_type,title,body,due_at,completed_at)
VALUES
    ('default',@e2e_deal_id,@e2e_person_id,@e2e_user_id,'followup','UI Acceptance Task','Complete me from Sales Today.',NOW()-INTERVAL 15 MINUTE,NULL);

DELETE FROM cos_approvals
WHERE organization_id='default' AND id='22222222222222222222222222222222';
DELETE FROM cos_actions
WHERE organization_id='default' AND id IN ('11111111111111111111111111111111','33333333333333333333333333333333');

INSERT INTO cos_actions
    (id,organization_id,type,target_type,target_id,parameters,source_type,source_id,status,execution_mode,risk_level,
     idempotency_key,correlation_id)
VALUES
    ('11111111111111111111111111111111','default','sales.create_task','deal',CAST(@e2e_deal_id AS CHAR),
     JSON_OBJECT('title','Approved acceptance task','due_in_minutes',60),'USER',CAST(@e2e_user_id AS CHAR),
     'PENDING_APPROVAL','APPROVAL_REQUIRED','LOW','ui-acceptance-approval-action','11111111111111111111111111111111'),
    ('33333333333333333333333333333333','default','sales.create_followup','deal',CAST(@e2e_deal_id AS CHAR),
     JSON_OBJECT('title','Executed acceptance follow-up','due_in_minutes',90),'USER',CAST(@e2e_user_id AS CHAR),
     'PROPOSED','MANUAL','LOW','ui-acceptance-execute-action','33333333333333333333333333333333');

INSERT INTO cos_approvals
    (id,organization_id,action_id,status,approver_type,approver_id,requested_by_type,requested_by_id,reason)
VALUES
    ('22222222222222222222222222222222','default','11111111111111111111111111111111','PENDING',
     'USER',CAST(@e2e_user_id AS CHAR),'USER',CAST(@e2e_user_id AS CHAR),'UI acceptance approval');
