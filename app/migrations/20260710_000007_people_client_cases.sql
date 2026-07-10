CREATE TABLE IF NOT EXISTS tn_people (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(160) NULL,
    telegram VARCHAR(80) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_people_public_id (public_id),
    KEY idx_tn_people_phone (phone),
    KEY idx_tn_people_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_client_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    type ENUM('buy', 'sell', 'rent', 'lease_out', 'repair', 'investment', 'management', 'inheritance', 'other') NOT NULL DEFAULT 'buy',
    title VARCHAR(220) NOT NULL,
    status ENUM('active', 'paused', 'closed', 'lost') NOT NULL DEFAULT 'active',
    stage ENUM('new', 'qualification', 'need_defined', 'matching', 'viewing', 'negotiation', 'deal', 'aftercare', 'repeat', 'paused', 'lost') NOT NULL DEFAULT 'new',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_user_id BIGINT UNSIGNED NULL,
    source VARCHAR(120) NULL,
    inbound_request_id BIGINT UNSIGNED NULL,
    property_type_id INT UNSIGNED NULL,
    location_id INT UNSIGNED NULL,
    budget_min DECIMAL(14, 2) NULL,
    budget_max DECIMAL(14, 2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    area_min DECIMAL(10, 2) NULL,
    area_max DECIMAL(10, 2) NULL,
    description TEXT NULL,
    parameters_json JSON NULL,
    started_at DATETIME NULL,
    next_contact_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_client_cases_public_id (public_id),
    KEY idx_tn_client_cases_person (person_id),
    KEY idx_tn_client_cases_stage (stage, priority, updated_at),
    KEY idx_tn_client_cases_status (status, type, updated_at),
    KEY idx_tn_client_cases_assigned_user (assigned_user_id),
    KEY idx_tn_client_cases_inbound_request (inbound_request_id),
    KEY idx_tn_client_cases_property_type (property_type_id),
    KEY idx_tn_client_cases_location (location_id),
    CONSTRAINT fk_tn_client_cases_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_client_cases_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES tn_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_client_cases_inbound_request FOREIGN KEY (inbound_request_id) REFERENCES tn_leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_client_cases_property_type FOREIGN KEY (property_type_id) REFERENCES tn_property_types (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_client_cases_location FOREIGN KEY (location_id) REFERENCES tn_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_client_case_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_case_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type ENUM('note', 'call', 'message', 'meeting', 'viewing', 'offer', 'status_change', 'deal', 'task') NOT NULL DEFAULT 'note',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_client_case_activities_case (client_case_id, created_at),
    KEY idx_tn_client_case_activities_person (person_id),
    KEY idx_tn_client_case_activities_due (due_at, completed_at),
    CONSTRAINT fk_tn_client_case_activities_case FOREIGN KEY (client_case_id) REFERENCES tn_client_cases (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_client_case_activities_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE SET NULL,
    CONSTRAINT fk_tn_client_case_activities_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_client_case_property_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_case_id BIGINT UNSIGNED NOT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    match_status ENUM('suggested', 'sent', 'interested', 'viewing', 'rejected', 'deal') NOT NULL DEFAULT 'suggested',
    score TINYINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_client_case_property_matches_item (client_case_id, property_id),
    KEY idx_tn_client_case_property_matches_case (client_case_id, match_status),
    KEY idx_tn_client_case_property_matches_property (property_id),
    CONSTRAINT fk_tn_client_case_property_matches_case FOREIGN KEY (client_case_id) REFERENCES tn_client_cases (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_client_case_property_matches_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_client_case_request_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_case_id BIGINT UNSIGNED NOT NULL,
    inbound_request_id BIGINT UNSIGNED NOT NULL,
    relation_type ENUM('source', 'follow_up', 'duplicate', 'context') NOT NULL DEFAULT 'context',
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_client_case_request_matches_item (client_case_id, inbound_request_id),
    KEY idx_tn_client_case_request_matches_request (inbound_request_id),
    CONSTRAINT fk_tn_client_case_request_matches_case FOREIGN KEY (client_case_id) REFERENCES tn_client_cases (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_client_case_request_matches_request FOREIGN KEY (inbound_request_id) REFERENCES tn_leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tn_leads
    ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER buyer_id,
    ADD COLUMN client_case_id BIGINT UNSIGNED NULL AFTER person_id,
    ADD KEY idx_tn_leads_person (person_id),
    ADD KEY idx_tn_leads_client_case (client_case_id),
    ADD CONSTRAINT fk_tn_leads_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tn_leads_client_case FOREIGN KEY (client_case_id) REFERENCES tn_client_cases (id) ON DELETE SET NULL;

INSERT IGNORE INTO tn_people (public_id, full_name, phone, email, telegram, notes, created_at, updated_at)
SELECT CONCAT('PN-BY-', b.id), b.full_name, b.phone, b.email, b.telegram, b.notes, b.created_at, b.updated_at
FROM tn_buyers b
WHERE NOT EXISTS (
    SELECT 1 FROM tn_people p WHERE p.public_id = CONCAT('PN-BY-', b.id)
);

INSERT IGNORE INTO tn_client_cases (
    public_id, person_id, type, title, status, stage, priority, assigned_user_id, source,
    budget_min, budget_max, currency, area_min, description, started_at, next_contact_at, created_at, updated_at
)
SELECT
    CONCAT('CC-BY-', b.id),
    p.id,
    CASE
        WHEN b.preferred_deal_type = 'rent' THEN 'rent'
        WHEN b.preferred_deal_type = 'investment' THEN 'investment'
        ELSE 'buy'
    END,
    CONCAT('Кейс: ', b.full_name),
    CASE WHEN b.funnel_stage IN ('paused') THEN 'paused' WHEN b.funnel_stage IN ('lost') THEN 'lost' ELSE 'active' END,
    b.funnel_stage,
    b.priority,
    b.assigned_user_id,
    b.source,
    b.budget_min,
    b.budget_max,
    b.budget_currency,
    b.area_min,
    b.notes,
    b.created_at,
    b.next_contact_at,
    b.created_at,
    b.updated_at
FROM tn_buyers b
INNER JOIN tn_people p ON p.public_id = CONCAT('PN-BY-', b.id)
WHERE NOT EXISTS (
    SELECT 1 FROM tn_client_cases c WHERE c.public_id = CONCAT('CC-BY-', b.id)
);

INSERT IGNORE INTO tn_client_case_activities (client_case_id, person_id, user_id, activity_type, title, body, due_at, completed_at, created_at)
SELECT c.id, c.person_id, a.user_id, a.activity_type, a.title, a.body, a.due_at, a.completed_at, a.created_at
FROM tn_buyer_activities a
INNER JOIN tn_client_cases c ON c.public_id = CONCAT('CC-BY-', a.buyer_id);

INSERT IGNORE INTO tn_client_case_property_matches (client_case_id, property_id, match_status, score, note, created_at, updated_at)
SELECT c.id, m.property_id, m.match_status, m.score, m.note, m.created_at, m.updated_at
FROM tn_buyer_matches m
INNER JOIN tn_client_cases c ON c.public_id = CONCAT('CC-BY-', m.buyer_id);

UPDATE tn_leads l
INNER JOIN tn_client_cases c ON c.public_id = CONCAT('CC-BY-', l.buyer_id)
SET l.person_id = c.person_id,
    l.client_case_id = c.id
WHERE l.buyer_id IS NOT NULL
  AND l.client_case_id IS NULL;

INSERT IGNORE INTO tn_client_case_request_matches (client_case_id, inbound_request_id, relation_type)
SELECT l.client_case_id, l.id, 'source'
FROM tn_leads l
WHERE l.client_case_id IS NOT NULL;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260710_000007_people_client_cases');
