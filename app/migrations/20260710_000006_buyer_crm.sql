CREATE TABLE IF NOT EXISTS tn_buyers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(160) NULL,
    telegram VARCHAR(80) NULL,
    segment ENUM('buyer', 'investor', 'renter', 'vip', 'partner') NOT NULL DEFAULT 'buyer',
    funnel_stage ENUM('new', 'qualification', 'need_defined', 'matching', 'viewing', 'negotiation', 'deal', 'aftercare', 'repeat', 'paused', 'lost') NOT NULL DEFAULT 'new',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    source VARCHAR(120) NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    budget_min DECIMAL(14, 2) NULL,
    budget_max DECIMAL(14, 2) NULL,
    budget_currency CHAR(3) NOT NULL DEFAULT 'USD',
    preferred_deal_type ENUM('any', 'sale', 'rent', 'investment') NOT NULL DEFAULT 'any',
    preferred_locations TEXT NULL,
    preferred_property_types TEXT NULL,
    rooms_min DECIMAL(4, 1) NULL,
    area_min DECIMAL(10, 2) NULL,
    notes TEXT NULL,
    last_contact_at DATETIME NULL,
    next_contact_at DATETIME NULL,
    ltv_amount DECIMAL(14, 2) NULL,
    ltv_currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_buyers_public_id (public_id),
    KEY idx_tn_buyers_funnel_stage (funnel_stage, priority, updated_at),
    KEY idx_tn_buyers_email (email),
    KEY idx_tn_buyers_phone (phone),
    KEY idx_tn_buyers_assigned_user (assigned_user_id),
    CONSTRAINT fk_tn_buyers_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_buyer_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    deal_type ENUM('sale', 'rent', 'investment', 'any') NOT NULL DEFAULT 'any',
    property_type VARCHAR(80) NULL,
    locations TEXT NULL,
    budget_min DECIMAL(14, 2) NULL,
    budget_max DECIMAL(14, 2) NULL,
    budget_currency CHAR(3) NOT NULL DEFAULT 'USD',
    rooms_min DECIMAL(4, 1) NULL,
    area_min DECIMAL(10, 2) NULL,
    must_have TEXT NULL,
    nice_to_have TEXT NULL,
    status ENUM('active', 'paused', 'closed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_buyer_requests_buyer (buyer_id, status),
    CONSTRAINT fk_tn_buyer_requests_buyer FOREIGN KEY (buyer_id) REFERENCES tn_buyers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_buyer_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type ENUM('note', 'call', 'message', 'meeting', 'viewing', 'offer', 'status_change', 'deal', 'task') NOT NULL DEFAULT 'note',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_buyer_activities_buyer (buyer_id, created_at),
    KEY idx_tn_buyer_activities_due (due_at, completed_at),
    CONSTRAINT fk_tn_buyer_activities_buyer FOREIGN KEY (buyer_id) REFERENCES tn_buyers (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_buyer_activities_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_buyer_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id BIGINT UNSIGNED NOT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    match_status ENUM('suggested', 'sent', 'interested', 'viewing', 'rejected', 'deal') NOT NULL DEFAULT 'suggested',
    score TINYINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_buyer_matches_item (buyer_id, property_id),
    KEY idx_tn_buyer_matches_buyer (buyer_id, match_status),
    KEY idx_tn_buyer_matches_property (property_id),
    CONSTRAINT fk_tn_buyer_matches_buyer FOREIGN KEY (buyer_id) REFERENCES tn_buyers (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_buyer_matches_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tn_leads
    ADD COLUMN buyer_id BIGINT UNSIGNED NULL AFTER id,
    ADD KEY idx_tn_leads_buyer (buyer_id),
    ADD CONSTRAINT fk_tn_leads_buyer FOREIGN KEY (buyer_id) REFERENCES tn_buyers (id) ON DELETE SET NULL;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260710_000006_buyer_crm');
