ALTER TABLE tn_growth_engagement_limit_profiles
    ADD COLUMN email_daily_limit INT UNSIGNED NULL AFTER daily_limit,
    ADD COLUMN linkedin_daily_limit INT UNSIGNED NULL AFTER email_daily_limit,
    ADD COLUMN phone_daily_limit INT UNSIGNED NULL AFTER linkedin_daily_limit;

UPDATE tn_growth_engagement_limit_profiles
SET email_daily_limit=COALESCE(email_daily_limit,daily_limit),
    linkedin_daily_limit=COALESCE(linkedin_daily_limit,daily_limit),
    phone_daily_limit=COALESCE(phone_daily_limit,daily_limit);

ALTER TABLE tn_growth_engagement_limit_profiles
    MODIFY COLUMN email_daily_limit INT UNSIGNED NOT NULL,
    MODIFY COLUMN linkedin_daily_limit INT UNSIGNED NOT NULL,
    MODIFY COLUMN phone_daily_limit INT UNSIGNED NOT NULL;

UPDATE cos_module_installations
SET installed_version='0.39.0',
    schema_version='0.39.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.38.0'
  AND schema_version='0.38.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000104_growth_v0390_channel_outreach_quotas');
