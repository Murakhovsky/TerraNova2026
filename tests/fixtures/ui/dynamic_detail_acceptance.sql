-- Dynamic UI detail acceptance fixtures.
-- Expects @web_user_id to be defined by the caller.

DELETE FROM tn_growth_experiment_assignments WHERE organization_id='default' AND experiment_id='UI-GEXP-DETAIL-001';
DELETE FROM tn_growth_experiments WHERE organization_id='default' AND experiment_id='UI-GEXP-DETAIL-001';
DELETE FROM tn_growth_candidate_signals WHERE organization_id='default' AND candidate_id='UI-CAND-DETAIL-001';
DELETE FROM tn_growth_candidates WHERE organization_id='default' AND candidate_id='UI-CAND-DETAIL-001';
DELETE FROM tn_growth_account_icp_matches WHERE organization_id='default' AND account_id='UI-ACCOUNT-DETAIL-001';
DELETE FROM tn_growth_account_snapshots WHERE organization_id='default' AND account_id='UI-ACCOUNT-DETAIL-001';
DELETE FROM tn_growth_accounts WHERE organization_id='default' AND account_id='UI-ACCOUNT-DETAIL-001';

INSERT INTO tn_growth_accounts
    (organization_id,account_id,name,canonical_domain,created_by,updated_by)
VALUES
    ('default','UI-ACCOUNT-DETAIL-001','Dynamic Account Acceptance','dynamic-detail.example',@web_user_id,@web_user_id);

INSERT INTO tn_growth_candidates
    (organization_id,candidate_id,opportunity_type,growth_mode,subject_type,subject_id,target_domain,status,
     rationale_json,score_json,qualification_reason,expected_value,recommended_play,recommended_action,
     created_by,updated_by)
VALUES
    ('default','UI-CAND-DETAIL-001','customer_acquisition','acquire','account','UI-ACCOUNT-DETAIL-001','sales','detected',
     NULL,NULL,NULL,'25000 USD','direct_outreach','Review this opportunity in Sales.',
     @web_user_id,@web_user_id);

INSERT INTO tn_growth_experiments
    (organization_id,experiment_id,name,hypothesis,dimension,primary_outcome,variants_json,status,
     started_at,ended_at,created_by,updated_by,created_at,updated_at)
VALUES
    ('default','UI-GEXP-DETAIL-001','Dynamic Experiment Acceptance',
     'A clearer message improves qualification rate.','message','qualified',
     JSON_ARRAY(
       JSON_OBJECT('key','control','name','Control','allocation_weight',50,'config',JSON_OBJECT('message','baseline')),
       JSON_OBJECT('key','variant','name','Variant','allocation_weight',50,'config',JSON_OBJECT('message','clearer'))
     ),
     'draft',NULL,NULL,@web_user_id,@web_user_id,NOW(6),NOW(6));

DELETE FROM cos_configuration_revisions
WHERE organization_id='default' AND domain_name='sales' AND configuration_type='RULE' AND entity_id='ui-dynamic-rule-001';
DELETE FROM cos_rules WHERE organization_id='default' AND id='ui-dynamic-rule-001';

INSERT INTO cos_rules
    (id,organization_id,domain_name,code,name,trigger_type,conditions,effect,version,priority,status,
     created_by_type,created_by_id,ownership,configuration_version,admin_modified_at)
VALUES
    ('ui-dynamic-rule-001','default','sales','sales.admin.dynamic_detail','Dynamic Rule Acceptance',
     'sales.deal.created',
     JSON_ARRAY(JSON_OBJECT('field','deal.status','operator','=','value','active')),
     JSON_OBJECT(
       'type','CREATE_ACTION',
       'action_type','sales.create_task',
       'target_type','deal',
       'target_id','{{event.aggregate_id}}',
       'parameters',JSON_OBJECT('title','Dynamic acceptance task'),
       'execution_mode','APPROVAL_REQUIRED',
       'risk_level','LOW'
     ),
     1,999,'DRAFT','USER',CAST(@web_user_id AS CHAR),'ADMIN',1,NOW(6));

DELETE FROM diagnostic_reports WHERE organization_id='default' AND session_id='ui-diagnostic-detail-001';
DELETE FROM diagnostic_runtime_sessions WHERE organization_id='default' AND session_id='ui-diagnostic-detail-001';
DELETE FROM diagnostic_sessions WHERE organization_id='default' AND session_id='ui-diagnostic-detail-001';
DELETE FROM diagnostic_packs WHERE organization_id='default' AND pack_id='ui-diagnostic-pack';

INSERT INTO diagnostic_packs
    (organization_id,pack_id,version,name,target_domain,status,methodology_json,content_hash,lock_version,published_at)
VALUES
    ('default','ui-diagnostic-pack',1,'Dynamic Diagnostic Pack','sales','published',JSON_OBJECT(),REPEAT('d',64),0,NOW(6));

INSERT INTO diagnostic_sessions
    (organization_id,session_id,pack_id,pack_version,target_domain,target_subject_type,target_subject_id,status,
     lock_version,started_at,completed_at)
VALUES
    ('default','ui-diagnostic-detail-001','ui-diagnostic-pack',1,'sales','company','ui-company','completed',
     1,NOW(6),NOW(6));

INSERT INTO diagnostic_reports
    (organization_id,session_id,report_version,report_json,state_revision,created_at)
VALUES
    ('default','ui-diagnostic-detail-001',1,
     JSON_OBJECT(
       'diagnosticId','Dynamic Diagnostic Acceptance',
       'executiveSummary','Dynamic diagnostic detail route is rendered from persisted report data.',
       'overallHealth',72.5,
       'coverage',0.84,
       'confidence',0.91,
       'topProblems',JSON_ARRAY(JSON_OBJECT('statement','No material blocker in acceptance fixture')),
       'recommendations',JSON_ARRAY(JSON_OBJECT('title','Keep deterministic UI acceptance'))
     ),
     1,NOW(6));

DELETE FROM tn_spatial_scenes WHERE public_id='SPS-UI-DYNAMIC-DETAIL-001' OR slug='ui-dynamic-detail-scene';
INSERT INTO tn_spatial_scenes
    (public_id,slug,title,description,scene_type,viewer_type,provider,status,external_url,
     default_camera_json,settings_json,created_by_user_id,published_at)
VALUES
    ('SPS-UI-DYNAMIC-DETAIL-001','ui-dynamic-detail-scene','Dynamic Spatial Acceptance',
     'Published Spatial fixture for dynamic route acceptance.','model','external','other','published',
     'https://example.com/spatial-dynamic-acceptance',JSON_OBJECT(),JSON_OBJECT(),@web_user_id,NOW());
