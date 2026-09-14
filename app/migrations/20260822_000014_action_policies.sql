INSERT INTO cos_policies (
    id, organization_id, code, name, action_type, conditions, decision, priority, version, status
)
VALUES
('policy-send-followup-auto-v1', 'default', 'send-followup-auto', 'Follow-up can run automatically',
 'sales.send_followup', JSON_ARRAY(), 'AUTO', 10, 1, 'ACTIVE'),
('policy-create-task-auto-v1', 'default', 'create-task-auto', 'Task creation can run automatically',
 'sales.create_task', JSON_ARRAY(), 'AUTO', 10, 1, 'ACTIVE'),
('policy-schedule-followup-auto-v1', 'default', 'schedule-followup-auto', 'Follow-up scheduling can run automatically',
 'sales.schedule_followup', JSON_ARRAY(), 'AUTO', 10, 1, 'ACTIVE'),
('policy-send-message-approval-v1', 'default', 'send-message-approval', 'General outbound messages require approval',
 'sales.send_message', JSON_ARRAY(), 'APPROVAL_REQUIRED', 10, 1, 'ACTIVE'),
('policy-update-deal-auto-v1', 'default', 'update-deal-auto', 'Deal updates can run automatically',
 'sales.update_deal', JSON_ARRAY(), 'AUTO', 10, 1, 'ACTIVE'),
('policy-discount-small-auto-v1', 'default', 'discount-small-auto', 'Discount up to 3 percent',
 'sales.apply_discount', JSON_ARRAY(JSON_OBJECT('field', 'action.parameters.discount_percent', 'operator', '<=', 'value', 3)),
 'AUTO', 10, 1, 'ACTIVE'),
('policy-discount-large-approval-v1', 'default', 'discount-large-approval', 'Discount above 3 percent requires approval',
 'sales.apply_discount', JSON_ARRAY(JSON_OBJECT('field', 'action.parameters.discount_percent', 'operator', '>', 'value', 3)),
 'APPROVAL_REQUIRED', 20, 1, 'ACTIVE'),
('policy-ai-delete-denied-v1', 'default', 'ai-delete-denied', 'AI cannot delete a customer',
 'sales.delete_customer', JSON_ARRAY(JSON_OBJECT('field', 'action.source_type', 'operator', '=', 'value', 'AGENT')),
 'DENIED', 1, 1, 'ACTIVE')
ON DUPLICATE KEY UPDATE
    name = VALUES(name), conditions = VALUES(conditions), decision = VALUES(decision),
    priority = VALUES(priority), status = VALUES(status), updated_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000014_action_policies');
