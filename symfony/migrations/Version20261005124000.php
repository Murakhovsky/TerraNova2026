<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005124000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LLM accounting: cached/reasoning tokens plus cost provenance and pricing version.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql("ALTER TABLE cos_llm_usage
            ADD cached_input_tokens INT NULL AFTER input_tokens,
            ADD reasoning_tokens INT NULL AFTER output_tokens,
            ADD cost_source VARCHAR(32) NULL AFTER cost_currency,
            ADD pricing_version VARCHAR(80) NULL AFTER cost_source");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('ALTER TABLE cos_llm_usage DROP cached_input_tokens, DROP reasoning_tokens, DROP cost_source, DROP pricing_version');
    }
}
