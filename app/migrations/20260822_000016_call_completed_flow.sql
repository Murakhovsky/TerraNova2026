INSERT INTO cos_rules (
    id, organization_id, code, name, trigger_type, conditions, effect, priority, version, status,
    created_by_type, created_by_id
)
VALUES (
    'rule-call-analysis-v1',
    'default',
    'call-completed-sales-intelligence',
    'Analyze completed sales calls',
    'sales.call.completed',
    JSON_ARRAY(),
    JSON_OBJECT(
        'type', 'CREATE_ACTION',
        'action_type', 'agent.run.sales_intelligence',
        'target_type', '{{event.aggregate_type}}',
        'target_id', '{{event.aggregate_id}}',
        'execution_mode', 'AUTO',
        'risk_level', 'LOW',
        'parameters', JSON_OBJECT(
            'question', 'Analyze the completed call and recommend the safest high-value next sales action.'
        )
    ),
    10,
    1,
    'ACTIVE',
    'SYSTEM',
    'cos-bootstrap'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name), conditions = VALUES(conditions), effect = VALUES(effect),
    priority = VALUES(priority), status = VALUES(status), updated_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000016_call_completed_flow');
