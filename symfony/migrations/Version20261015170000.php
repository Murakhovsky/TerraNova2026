<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Migration ledger fences legacy writers; no trigger, data replay or dual write. */
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Federation single-authority historical Run cutover journal and immutable preflight evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,'MySQL required.');
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_execution_migrations (
    organization_id VARCHAR(190) NOT NULL,
    legacy_run_id VARCHAR(64) NOT NULL,
    plan_id VARCHAR(64) NOT NULL,
    canonical_run_id VARCHAR(190) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    approval_reference VARCHAR(190) NOT NULL,
    parity_status VARCHAR(24) NOT NULL,
    migration_state VARCHAR(24) NOT NULL,
    operator_id VARCHAR(190) NOT NULL,
    migrated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, legacy_run_id),
    UNIQUE KEY uq_fed_migration_plan (organization_id, plan_id),
    UNIQUE KEY uq_fed_migration_canonical (organization_id, canonical_run_id),
    CONSTRAINT fk_fed_migration_legacy FOREIGN KEY (organization_id,legacy_run_id)
        REFERENCES cos_federation_runs (organization_id,run_id),
    CONSTRAINT fk_fed_migration_canonical FOREIGN KEY (organization_id,canonical_run_id)
        REFERENCES cos_workflow_runs (organization_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true,'Historical execution fence cannot be deleted without manual source reconciliation.');
    }
}
