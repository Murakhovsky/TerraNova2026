CREATE TABLE IF NOT EXISTS cos_llm_budgets (
    organization_id VARCHAR(40) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    monthly_limit DECIMAL(14,4) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, currency),
    CONSTRAINT chk_cos_llm_budget_non_negative CHECK (monthly_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_llm_usage (
    id CHAR(32) NOT NULL,
    organization_id VARCHAR(40) NULL,
    correlation_id VARCHAR(128) NOT NULL,
    use_case VARCHAR(160) NULL,
    provider VARCHAR(80) NOT NULL,
    model VARCHAR(160) NOT NULL,
    input_tokens INT NULL,
    output_tokens INT NULL,
    cost_amount DECIMAL(14,6) NULL,
    cost_currency CHAR(3) NULL,
    latency_ms INT NOT NULL,
    fallback_count INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_llm_usage_org_month (organization_id, created_at),
    KEY idx_cos_llm_usage_correlation (correlation_id),
    KEY idx_cos_llm_usage_use_case (use_case, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000041_cos_kernel_v010_llm_governance');
