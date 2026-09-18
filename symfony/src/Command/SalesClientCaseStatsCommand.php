<?php
declare(strict_types=1);

namespace App\Command;

use App\Application\Sales\Query\GetClientCaseStatsQuery;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Shared\Domain\OrganizationId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:sales:client-case-stats', description: 'Read tenant-scoped Sales client-case statistics through the canonical query path.')]
final class SalesClientCaseStatsCommand extends Command
{
    public function __construct(private readonly QueryBusInterface $queries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) $input->getOption('organization'));
        if ($organizationId === '') {
            $output->writeln('<error>--organization is required.</error>');
            return Command::INVALID;
        }

        $result = $this->queries->ask(
            new GetClientCaseStatsQuery(OrganizationId::fromString($organizationId)),
        );
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
