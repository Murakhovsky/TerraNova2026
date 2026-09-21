CREATE TABLE IF NOT EXISTS cos_feature_flags (
    flag_key VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    rollout_percentage SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rollout_salt VARCHAR(120) NOT NULL,
    starts_at DATETIME(6) NULL,
    ends_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (flag_key),
    CONSTRAINT chk_cos_feature_flags_rollout CHECK (rollout_percentage <= 100),
    CONSTRAINT chk_cos_feature_flags_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_feature_flag_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    flag_key VARCHAR(120) NOT NULL,
    organization_id VARCHAR(190) NOT NULL,
    subject_type ENUM('ORGANIZATION','USER') NOT NULL,
    subject_id VARCHAR(190) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_feature_flag_override (flag_key, organization_id, subject_type, subject_id),
    KEY ix_cos_feature_flag_override_lookup (organization_id, flag_key, subject_type, subject_id, expires_at),
    CONSTRAINT fk_cos_feature_flag_override_flag
        FOREIGN KEY (flag_key) REFERENCES cos_feature_flags(flag_key)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
