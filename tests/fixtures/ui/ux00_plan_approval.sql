-- UX-00 TWO-INDEPENDENT-MANAGERS Plan Approval.
-- Disposable CI database ONLY, never apply to a shared tenant.
INSERT INTO cos_organization_modules (organization_id,module_id,enabled)
VALUES ('default','federation',1)
ON DUPLICATE KEY UPDATE enabled=1,updated_at=CURRENT_TIMESTAMP;

INSERT INTO cos_module_installations
    (organization_id,module_id,status,installed_version,schema_version)
VALUES ('default','federation','INSTALLED','1.0.0','1.0.0')
ON DUPLICATE KEY UPDATE status='INSTALLED',
 installed_version='1.0.0',schema_version='1.0.0',updated_at=CURRENT_TIMESTAMP;

INSERT INTO cos_policies
    (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
VALUES
    ('policy-ux00-plan-approval','default','ux00-plan-approval',
     'Isolated UX00 independent human plan policy',
     'cos.federation.plan.approval',JSON_ARRAY(),'APPROVAL_REQUIRED',10,1,'ACTIVE')
ON DUPLICATE KEY UPDATE
 conditions=VALUES(conditions),decision=VALUES(decision),
 priority=VALUES(priority),status=VALUES(status),updated_at=CURRENT_TIMESTAMP;
