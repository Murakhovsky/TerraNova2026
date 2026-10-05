<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering observability: immutable execution event journal for tools, repository actions and runtime operations.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_execution_events (
    id VARCHAR(36) NOT NULL,
    feature_id VARCHAR(36) NOT NULL,
    workflow_execution_id VARCHAR(36) NOT NULL,
    agent_run_id VARCHAR(36) NULL,
    correlation_id VARCHAR(128) NULL,
    category VARCHAR(32) NOT NULL,
    action VARCHAR(128) NOT NULL,
    status VARCHAR(24) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    details JSON NULL,
    duration_ms INT NULL,
    error TEXT NULL,
    occurred_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_exec_workflow_time (workflow_execution_id, occurred_at),
    KEY idx_cos_eng_exec_feature_time (feature_id, occurred_at),
    KEY idx_cos_eng_exec_agent (agent_run_id),
    KEY idx_cos_eng_exec_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );
        $this->addSql('DROP TABLE cos_engineering_execution_events');
    }
}
