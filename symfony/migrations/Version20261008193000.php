<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A plan must never issue two unrelated execution runs. Retry/recovery must
 * reuse the original run and idempotency keys.
 */
final class Version20261008193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'COS Federation: one execution run per tenant-owned approved plan.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL required.');
        $this->addSql('ALTER TABLE cos_federation_runs ADD UNIQUE KEY uq_federation_plan_run (organization_id, plan_id)');
    }

    public function down(Schema $schema): void
    {
        // Safe, additive rollback of this constraint, preserving all business records.
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL required.');
        $this->addSql('ALTER TABLE cos_federation_runs DROP INDEX uq_federation_plan_run');
    }
}
