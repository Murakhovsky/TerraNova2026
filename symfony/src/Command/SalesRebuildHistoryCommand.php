<?php
declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesDealOwnerHistoryRebuilder;
use Domains\Sales\Application\Service\SalesDealStageHistoryRebuilder;
use Domains\Sales\Application\Service\SalesHistoricalIntelligenceHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:sales:history:rebuild', description: 'Rebuild Sales stage/owner historical projections for one organization.')]
final class SalesRebuildHistoryCommand extends Command
{
    public function __construct(
        private readonly SalesDealStageHistoryRebuilder $stage,
        private readonly SalesDealOwnerHistoryRebuilder $owner,
        private readonly SalesHistoricalIntelligenceHealthService $health,
    ) {
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

        $output->writeln(json_encode([
            'organization_id' => $organizationId,
            'stage' => $this->stage->rebuildOrganization($organizationId),
            'owner' => $this->owner->rebuildOrganization($organizationId),
            'health' => $this->health->check($organizationId),
            'completed_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
