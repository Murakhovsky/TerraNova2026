<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opt-in, scoped canonical technical AgentRun execution. No bulk backfill:
 * old runs are examined read-only; no historical replay is attempted.
 */
final class Version20261015032000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering canonical technical execution link and explicit per-feature rollout allowlist.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'MySQL required.');
        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_technical_rollouts (
    organization_id VARCHAR(190) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    mode VARCHAR(24) NOT NULL DEFAULT 'off',
    approved_by VARCHAR(190) NOT NULL,
    approved_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, feature_id),
    KEY idx_engineering_rollout_mode (organization_id, mode),
    CONSTRAINT fk_engineering_rollout_feature FOREIGN KEY (feature_id)
        REFERENCES cos_engineering_features (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_technical_links (
    organization_id VARCHAR(190) NOT NULL,
    agent_run_id CHAR(36) NOT NULL,
    engineering_workflow_id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    canonical_run_id VARCHAR(190) NOT NULL,
    agent_idempotency_key VARCHAR(190) NOT NULL,
    claim_token CHAR(32) NOT NULL,
    technical_state VARCHAR(24) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, agent_run_id),
    UNIQUE KEY uq_eng_canonical_run (organization_id, canonical_run_id),
    UNIQUE KEY uq_eng_canonical_idempotency (organization_id, agent_idempotency_key),
    KEY idx_eng_canonical_feature_state (organization_id, feature_id, technical_state),
    CONSTRAINT fk_eng_canonical_link_run FOREIGN KEY (organization_id, canonical_run_id)
        REFERENCES cos_workflow_runs (organization_id, run_id),
    CONSTRAINT fk_eng_canonical_link_agent FOREIGN KEY (agent_run_id)
        REFERENCES cos_engineering_agent_runs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true,'Engineering canonical claims cannot be rolled back automatically.');
    }
}
