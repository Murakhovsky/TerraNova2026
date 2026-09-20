<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920181500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Database cutover Wave 1: Platform operational metrics, resilience circuits and LLM governance stores.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_external_circuits (
    organization_id VARCHAR(64) NOT NULL,
    service_key VARCHAR(128) NOT NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    opened_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, service_key),
    KEY idx_cos_external_circuits_open (opened_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_operational_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NULL,
    metric VARCHAR(160) NOT NULL,
    value DECIMAL(20,6) NOT NULL,
    labels JSON NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_metrics_name_time (metric, recorded_at),
    KEY idx_cos_metrics_org_time (organization_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_llm_budgets (
    organization_id VARCHAR(40) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    monthly_limit DECIMAL(14,4) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, currency),
    CONSTRAINT chk_cos_llm_budget_non_negative CHECK (monthly_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        // Cutover migrations are intentionally irreversible. Dropping canonical
        // operational data would make rollback less safe than retaining it.
    }
}
