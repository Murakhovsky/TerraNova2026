ALTER TABLE tn_analytics_events
    MODIFY event_type ENUM(
        'property_view',
        'phone_click',
        'telegram_click',
        'viber_click',
        'presentation_request',
        'presentation_download',
        'presentation_share',
        'lead_submit',
        'property_submit'
    ) NOT NULL;

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
        'task'
    ) NOT NULL DEFAULT 'note';

ALTER TABLE tn_property_activities
    MODIFY activity_type ENUM(
        'note',
        'status_change',
        'details_update',
        'media_update',
        'moderation',
        'presentation',
        'system'
    ) NOT NULL DEFAULT 'note';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260823_000014_p2_presentations');
