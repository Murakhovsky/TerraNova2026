CREATE TABLE IF NOT EXISTS tn_growth_engagement_sequence_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, organization_id VARCHAR(64) NOT NULL, profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL, enabled TINYINT(1) NOT NULL, allowed_channels_json JSON NOT NULL,
    max_touches INT UNSIGNED NOT NULL, follow_up_delay_hours INT UNSIGNED NOT NULL, max_advances_per_run INT UNSIGNED NOT NULL,
    activation_started_at DATETIME(6) NULL, reason VARCHAR(1000) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_growth_sequence_profile (organization_id,profile_id),
    UNIQUE KEY uq_growth_sequence_profile_revision (organization_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_sequences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, organization_id VARCHAR(64) NOT NULL, sequence_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL, root_recommendation_id VARCHAR(80) NOT NULL, contact_id VARCHAR(80) NOT NULL,
    channel VARCHAR(40) NOT NULL, status VARCHAR(24) NOT NULL, touch_count INT UNSIGNED NOT NULL, max_touches INT UNSIGNED NOT NULL,
    last_recommendation_id VARCHAR(80) NOT NULL, next_due_at DATETIME(6) NULL, stop_code VARCHAR(120) NULL, stop_reason VARCHAR(1000) NULL,
    started_by BIGINT UNSIGNED NOT NULL, started_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_growth_sequence (organization_id,sequence_id),
    UNIQUE KEY uq_growth_sequence_root (organization_id,root_recommendation_id),
    KEY ix_growth_sequence_candidate (organization_id,candidate_id,started_at),
    KEY ix_growth_sequence_active_due (organization_id,status,next_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_sequence_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, organization_id VARCHAR(64) NOT NULL, step_id VARCHAR(80) NOT NULL,
    sequence_id VARCHAR(80) NOT NULL, touch_number INT UNSIGNED NOT NULL, recommendation_id VARCHAR(80) NOT NULL,
    parent_recommendation_id VARCHAR(80) NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id), UNIQUE KEY uq_growth_sequence_step (organization_id,step_id),
    UNIQUE KEY uq_growth_sequence_touch (organization_id,sequence_id,touch_number),
    UNIQUE KEY uq_growth_sequence_recommendation (organization_id,recommendation_id),
    KEY ix_growth_sequence_steps_sequence (organization_id,sequence_id,touch_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations SET installed_version='0.44.0',schema_version='0.44.0',updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth' AND status='INSTALLED' AND installed_version='0.43.0' AND schema_version='0.43.0';

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20260926_000109_growth_v0440_outreach_sequences');
