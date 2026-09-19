<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Database\MigrationRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:legacy-schema:status',
    description: 'Report applied canonical COS SQL migrations in the shared business schema.',
)]
final class LegacySchemaStatusCommand extends Command
{
    public function __construct(private readonly MigrationRunnerInterface $migrations)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode(
            $this->migrations->status(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));

        return Command::SUCCESS;
    }
}
