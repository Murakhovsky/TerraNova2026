CREATE TABLE IF NOT EXISTS cos_security_rate_limits (
    key_hash CHAR(64) NOT NULL,
    bucket VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME(6) NOT NULL,
    blocked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (key_hash),
    KEY idx_cos_security_rate_limits_bucket (bucket),
    KEY idx_cos_security_rate_limits_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
