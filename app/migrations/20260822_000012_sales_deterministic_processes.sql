INSERT INTO cos_rules (
    id, organization_id, code, name, trigger_type, conditions, effect,
    version, priority, status, created_by_type, created_by_id
)
VALUES
(
    'sales-new-deal-qualification-v1',
    'default',
    'sales-new-deal-qualification',
    'Новий Deal потребує кваліфікації',
    'sales.deal.created',
    JSON_ARRAY(
        JSON_OBJECT('field', 'deal.status', 'operator', '=', 'value', 'active'),
        JSON_OBJECT('field', 'deal.stage', 'operator', '=', 'value', 'new')
    ),
    JSON_OBJECT(
        'type', 'CREATE_ACTION',
        'action_type', 'sales.create_qualification_task',
        'target_type', 'deal',
        'target_id', '{{event.aggregate_id}}',
        'parameters', JSON_OBJECT(
            'title', 'Кваліфікувати новий запит',
            'body', 'Уточнити потребу, бюджет, термін і особу, яка приймає рішення.',
            'due_in_minutes', 240
        ),
        'execution_mode', 'AUTO',
        'risk_level', 'LOW'
    ),
    1, 10, 'ACTIVE', 'SYSTEM', 'cos-bootstrap'
),
(
    'sales-stage-without-next-contact-v1',
    'default',
    'sales-stage-without-next-contact',
    'Активний Deal без наступного контакту',
    'sales.deal.stage_changed',
    JSON_ARRAY(
        JSON_OBJECT('field', 'deal.status', 'operator', '=', 'value', 'active'),
        JSON_OBJECT(
            'field', 'deal.stage',
            'operator', 'IN',
            'value', JSON_ARRAY('qualification', 'need_defined', 'matching', 'viewing', 'negotiation')
        ),
        JSON_OBJECT('field', 'deal.next_contact_at', 'operator', 'IS_NULL')
    ),
    JSON_OBJECT(
        'type', 'CREATE_ACTION',
        'action_type', 'sales.create_followup_task',
        'target_type', 'deal',
        'target_id', '{{event.aggregate_id}}',
        'parameters', JSON_OBJECT(
            'title', 'Запланувати наступний контакт',
            'body', 'У Deal немає зафіксованого наступного кроку.',
            'due_in_minutes', 1440
        ),
        'execution_mode', 'AUTO',
        'risk_level', 'LOW'
    ),
    1, 20, 'ACTIVE', 'SYSTEM', 'cos-bootstrap'
),
(
    'sales-overdue-followup-escalation-v1',
    'default',
    'sales-overdue-followup-escalation',
    'Прострочений follow-up потребує ескалації',
    'sales.followup.overdue',
    JSON_ARRAY(
        JSON_OBJECT('field', 'deal.status', 'operator', '=', 'value', 'active'),
        JSON_OBJECT('field', 'activity.completed_at', 'operator', 'IS_NULL'),
        JSON_OBJECT('field', 'activity.is_overdue', 'operator', '=', 'value', CAST('true' AS JSON))
    ),
    JSON_OBJECT(
        'type', 'CREATE_ACTION',
        'action_type', 'sales.escalate_overdue_followup',
        'target_type', 'deal',
        'target_id', '{{event.aggregate_id}}',
        'parameters', JSON_OBJECT(
            'title', 'Терміново: прострочений follow-up',
            'body', 'Попередня задача не виконана в строк. Потрібен контакт або рішення менеджера.',
            'due_in_minutes', 60
        ),
        'execution_mode', 'AUTO',
        'risk_level', 'MEDIUM'
    ),
    1, 30, 'ACTIVE', 'SYSTEM', 'cos-bootstrap'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    trigger_type = VALUES(trigger_type),
    conditions = VALUES(conditions),
    effect = VALUES(effect),
    priority = VALUES(priority),
    status = VALUES(status),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO cos_policies (
    id, organization_id, code, name, action_type, conditions,
    decision, priority, version, status
)
VALUES
(
    'sales-auto-qualification-task-v1',
    'default',
    'sales-auto-qualification-task',
    'Автоматичне створення задачі кваліфікації',
    'sales.create_qualification_task',
    JSON_ARRAY(),
    'AUTO', 10, 1, 'ACTIVE'
),
(
    'sales-auto-followup-task-v1',
    'default',
    'sales-auto-followup-task',
    'Автоматичне створення follow-up задачі',
    'sales.create_followup_task',
    JSON_ARRAY(),
    'AUTO', 10, 1, 'ACTIVE'
),
(
    'sales-auto-overdue-escalation-v1',
    'default',
    'sales-auto-overdue-escalation',
    'Автоматична ескалація простроченого follow-up',
    'sales.escalate_overdue_followup',
    JSON_ARRAY(),
    'AUTO', 10, 1, 'ACTIVE'
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    action_type = VALUES(action_type),
    conditions = VALUES(conditions),
    decision = VALUES(decision),
    priority = VALUES(priority),
    status = VALUES(status),
    updated_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260822_000012_sales_deterministic_processes');
