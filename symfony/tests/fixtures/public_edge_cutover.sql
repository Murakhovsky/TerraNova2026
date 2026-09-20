CREATE TABLE IF NOT EXISTS tn_analytics_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL DEFAULT 'system',
    entity_id BIGINT UNSIGNED NULL,
    property_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    source_page VARCHAR(255) NULL,
    utm_source VARCHAR(120) NULL,
    utm_medium VARCHAR(120) NULL,
    utm_campaign VARCHAR(160) NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
