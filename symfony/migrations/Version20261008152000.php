<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering V0.1: deterministic workflow transition ordering via explicit sequence number.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('ALTER TABLE cos_engineering_transitions ADD sequence_no INT NULL AFTER metadata');

        $this->addSql(<<<'SQL'
UPDATE cos_engineering_transitions target
JOIN (
    SELECT ordered.id, ordered.sequence_no
    FROM (
        SELECT
            id,
            ROW_NUMBER() OVER (
                PARTITION BY workflow_execution_id
                ORDER BY created_at ASC, id ASC
            ) AS sequence_no
        FROM cos_engineering_transitions
    ) ordered
) ranked ON ranked.id = target.id
SET target.sequence_no = ranked.sequence_no
SQL);

        $this->addSql('ALTER TABLE cos_engineering_transitions MODIFY sequence_no INT NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_cos_eng_transition_sequence ON cos_engineering_transitions (workflow_execution_id, sequence_no)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cos_eng_transition_sequence ON cos_engineering_transitions');
        $this->addSql('ALTER TABLE cos_engineering_transitions DROP sequence_no');
    }
}
