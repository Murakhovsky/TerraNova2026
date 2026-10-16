<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frozen cutover proof and append-only operator authorization journal.
 * Safe for existing migrations: old ledger rows keep NULL new baselines,
 * which post-cutover checks report as requiring reconciliation, never PASS.
 */
final class Version20261015183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Federation migration operator permit, immutable canonical baseline and accepted audit journal';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'MySQL required.',
        );
        $this->addSql('ALTER TABLE cos_federation_execution_migrations
            ADD COLUMN canonical_identity_sha256 CHAR(64) NULL AFTER source_sha256,
            ADD COLUMN permit_sha256 CHAR(64) NULL AFTER canonical_identity_sha256');
        $this->addSql(<<<'SQL'
CREATE TABLE cos_federation_migration_audit (
    organization_id VARCHAR(190) NOT NULL,
    legacy_run_id VARCHAR(64) NOT NULL,
    event_code VARCHAR(48) NOT NULL,
    operator_id VARCHAR(190) NOT NULL,
    source_sha256 CHAR(64) NOT NULL,
    canonical_identity_sha256 CHAR(64) NOT NULL,
    permit_sha256 CHAR(64) NOT NULL,
    event_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,legacy_run_id,event_code),
    CONSTRAINT fk_fed_migration_audit_source
        FOREIGN KEY (organization_id,legacy_run_id)
        REFERENCES cos_federation_execution_migrations (organization_id,legacy_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true,
            'Immutable operator authorization / migration audit cannot be removed automatically.');
    }
}
