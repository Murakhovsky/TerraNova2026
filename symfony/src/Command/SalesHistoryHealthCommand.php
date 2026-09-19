<?php
declare(strict_types=1);

namespace App\Command;

use Domains\Sales\Application\Service\SalesHistoricalIntelligenceHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:sales:history:health', description: 'Check Sales historical projection integrity for one organization.')]
final class SalesHistoryHealthCommand extends Command
{
    public function __construct(private readonly SalesHistoricalIntelligenceHealthService $health)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) ($input->getOption('organization') ?? ''));
        if ($organizationId === '') {
            $output->writeln('<error>--organization is required.</error>');
            return Command::INVALID;
        }

        $output->writeln(json_encode(
            $this->health->check($organizationId),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        return Command::SUCCESS;
    }
}
