<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\CrossVenueUniverse;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueDiscoverySnapshotRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/** Scheduler-friendly read-only discovery. Never approves a relationship or places an order. */
#[AsCommand(
    name: 'cos:capital-markets:discovery:run',
    description: 'Reconcile historical bStocks candidates with public venue listing catalogs.'
)]
final class CapitalMarketsInstrumentDiscoveryCommand extends Command
{
    public function __construct(
        private readonly CrossVenueUniverse $universe,
        private readonly CrossVenueCatalogDiscovery $discovery,
        private readonly CrossVenueDiscoverySnapshotRepository $snapshots,
        private readonly CrossVenuePoolCandidateRepository $pool,
        #[Autowire('%kernel.project_dir%/../resources/capital-markets/universes/binance-bstocks-2026-09-27.csv')]
        private readonly string $file,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('organization', InputArgument::REQUIRED, 'Tenant organization ID')
            ->addArgument('actor', InputArgument::REQUIRED, 'Authorized operator/service actor ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '10 or 80 candidates', '80');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $org = trim((string)$input->getArgument('organization'));
        $actor = filter_var($input->getArgument('actor'), FILTER_VALIDATE_INT);
        $limit = (string)$input->getOption('limit');
        if ($org === '' || strlen($org) > 190 || $actor === false || $actor < 1
            || !in_array($limit, ['10','80'], true)) {
            $output->writeln('INVALID_SCOPE');
            return Command::INVALID;
        }
        try {
            if (!$this->snapshots->reserveScan($org, $actor)) {
                $output->writeln('RATE_LIMITED');
                return Command::FAILURE;
            }
            $seeds = $this->universe->load($this->file);
            $result = $this->discovery->scan($org, array_slice($seeds, 0, (int)$limit));
            $this->snapshots->record($org, $actor, $result);
            $count = $this->pool->reconcile($org, $result);
            $output->writeln(json_encode([
                'status'=>'RECORDED','pool'=>'BINANCE_BSTOCKS_SEED','observations'=>$count,
                'source_health'=>$result['source_health'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(json_encode([
                'status'=>'FAILED','error_type'=>get_class($e),
            ], JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
