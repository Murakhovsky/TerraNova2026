CREATE TABLE IF NOT EXISTS cos_llm_budget_reservations (
    id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    currency CHAR(3) NOT NULL,
    reserved_amount DECIMAL(14,4) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_cos_llm_reservations_budget (organization_id, currency, expires_at),
    CONSTRAINT chk_cos_llm_reservation_amount CHECK (reserved_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
