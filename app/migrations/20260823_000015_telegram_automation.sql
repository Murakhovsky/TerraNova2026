CREATE TABLE IF NOT EXISTS tn_telegram_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    telegram_user_id BIGINT NOT NULL,
    chat_id BIGINT NOT NULL,
    username VARCHAR(80) NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
    verified_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_telegram_bindings_telegram_user (telegram_user_id),
    UNIQUE KEY uq_tn_telegram_bindings_user_chat (user_id, chat_id),
    UNIQUE KEY uq_tn_telegram_bindings_person_chat (person_id, chat_id),
    KEY idx_tn_telegram_bindings_user (user_id, notifications_enabled),
    KEY idx_tn_telegram_bindings_person (person_id, notifications_enabled),
    CONSTRAINT fk_tn_telegram_bindings_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_telegram_bindings_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_telegram_link_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_telegram_link_tokens_hash (token_hash),
    KEY idx_tn_telegram_link_tokens_expiry (expires_at, used_at),
    CONSTRAINT fk_tn_telegram_link_tokens_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_telegram_link_tokens_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    audience ENUM('admins', 'team', 'user', 'person', 'chat') NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    chat_id BIGINT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id BIGINT UNSIGNED NULL,
    payload JSON NOT NULL,
    dedupe_key VARCHAR(190) NULL,
    status ENUM('pending', 'processing', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    lock_token CHAR(32) NULL,
    sent_at DATETIME NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_notification_outbox_dedupe (dedupe_key),
    KEY idx_tn_notification_outbox_delivery (status, available_at, attempts),
    KEY idx_tn_notification_outbox_user (user_id, status),
    KEY idx_tn_notification_outbox_person (person_id, status),
    CONSTRAINT fk_tn_notification_outbox_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_notification_outbox_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260823_000015_telegram_automation');
