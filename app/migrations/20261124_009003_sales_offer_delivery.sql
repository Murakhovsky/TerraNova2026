CREATE TABLE IF NOT EXISTS sales_offer_deliveries (
    organization_id VARCHAR(40) NOT NULL,
    offer_id CHAR(32) NOT NULL,
    terms_version INT UNSIGNED NOT NULL,
    notification_id VARCHAR(64) NOT NULL,
    recipient_hash CHAR(64) NOT NULL,
    document_hash CHAR(64) NOT NULL,
    action_id VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,offer_id,terms_version),
    UNIQUE KEY uq_sales_offer_notification (organization_id,notification_id),
    CONSTRAINT fk_sales_offer_delivery_terms FOREIGN KEY (organization_id,offer_id,terms_version) REFERENCES sales_offer_revisions(organization_id,offer_id,terms_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261124_009003_sales_offer_delivery');
