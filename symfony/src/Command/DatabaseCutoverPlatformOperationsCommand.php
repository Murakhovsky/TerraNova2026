<?php
declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Migration\Database\PlatformOperationsDatabaseCutover;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:database:cutover:platform-operations',
    description: 'Copy and verify independent Platform operational stores from legacy MySQL into canonical COS MySQL.',
)]
final class DatabaseCutoverPlatformOperationsCommand extends Command
{
    public function __construct(private readonly PlatformOperationsDatabaseCutover $cutover)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('verify-only', null, InputOption::VALUE_NONE, 'Verify canonical target without copying legacy rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $input->getOption('verify-only')
            ? $this->cutover->verifyCanonical()
            : $this->cutover->migrate();

        $output->writeln(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return Command::SUCCESS;
    }
}
