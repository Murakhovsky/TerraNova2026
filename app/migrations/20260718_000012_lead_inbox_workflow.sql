ALTER TABLE tn_leads
    MODIFY status ENUM('new', 'contacted', 'qualified', 'viewing', 'negotiation', 'won', 'lost', 'spam', 'closed') NOT NULL DEFAULT 'new';

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'assigned_user_id'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN assigned_user_id BIGINT UNSIGNED NULL AFTER client_case_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'manager_note'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN manager_note TEXT NULL AFTER message',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'last_contacted_at'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN last_contacted_at DATETIME NULL AFTER manager_note',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND COLUMN_NAME = 'next_contact_at'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE tn_leads ADD COLUMN next_contact_at DATETIME NULL AFTER last_contacted_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND INDEX_NAME = 'idx_tn_leads_assigned_status'
);
SET @sql = IF(
    @index_exists = 0,
    'ALTER TABLE tn_leads ADD KEY idx_tn_leads_assigned_status (assigned_user_id, status, next_contact_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tn_leads'
      AND CONSTRAINT_NAME = 'fk_tn_leads_assigned_user'
);
SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE tn_leads ADD CONSTRAINT fk_tn_leads_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES tn_users (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS tn_lead_activities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lead_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type ENUM('note', 'call', 'message', 'status_change', 'task', 'viewing') NOT NULL DEFAULT 'note',
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    due_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_lead_activities_lead (lead_id, created_at),
    KEY idx_tn_lead_activities_due (due_at, completed_at),
    CONSTRAINT fk_tn_lead_activities_lead FOREIGN KEY (lead_id) REFERENCES tn_leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_tn_lead_activities_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
