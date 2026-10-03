-- Production Cutover repair: Sales runtime models follow-up as a first-class activity type.
-- Persistence services, Today queues, attention scanning and Director metrics already use
-- activity_type='followup', but the canonical ENUM never added the value.
ALTER TABLE tn_client_case_activities
    MODIFY activity_type ENUM(
        'note',
        'call',
        'message',
        'meeting',
        'viewing',
        'offer',
        'presentation',
        'status_change',
        'deal',
        'task',
        'followup'
    ) NOT NULL DEFAULT 'note';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261003_000122_sales_followup_activity_type');
