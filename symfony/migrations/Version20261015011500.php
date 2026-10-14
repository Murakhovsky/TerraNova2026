<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additive canonical technical execution schema. This migration does not touch
 * Federation, Engineering or AgentRun state. No cutover, trigger or dual write.
 */
final class Version20261015011500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'COS universal tenant-scoped durable Workflow Run/Step storage (inactive until parity).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL required.');
        $this->addSql(<<<'SQL'
CREATE TABLE cos_workflow_runs (
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    definition_id VARCHAR(190) NOT NULL,
    definition_version VARCHAR(64) NOT NULL,
    definition_json JSON NOT NULL,
    definition_digest CHAR(64) NOT NULL,
    input_digest CHAR(64) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    correlation_id VARCHAR(190) NOT NULL,
    state VARCHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    source_plan_id VARCHAR(190) NULL,
    source_plan_digest CHAR(64) NULL,
    approval_reference VARCHAR(190) NULL,
    checkpoint_reference VARCHAR(190) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, run_id),
    UNIQUE KEY uq_workflow_plan_run (organization_id, source_plan_id),
    KEY idx_workflow_org_state (organization_id, state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_workflow_steps (
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    step_id VARCHAR(64) NOT NULL,
    ordinal_no INT UNSIGNED NOT NULL,
    capability_id VARCHAR(190) NOT NULL,
    capability_version VARCHAR(64) NOT NULL,
    side_effect_level VARCHAR(24) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'pending',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    idempotency_key CHAR(64) NOT NULL,
    claim_token CHAR(32) NULL,
    claimed_by VARCHAR(190) NULL,
    claim_expires_at DATETIME(6) NULL,
    result_reference VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, run_id, step_id),
    UNIQUE KEY uq_workflow_step_ordinal (organization_id, run_id, ordinal_no),
    UNIQUE KEY uq_workflow_step_key (organization_id, idempotency_key),
    KEY idx_workflow_step_state (organization_id, state, updated_at),
    CONSTRAINT fk_workflow_step_run FOREIGN KEY (organization_id, run_id)
        REFERENCES cos_workflow_runs (organization_id, run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Durable Workflow evidence must not be deleted by rollback. Disable the feature instead.');
    }
}
