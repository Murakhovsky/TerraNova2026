<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Handler;

use App\Application\CapitalMarkets\Command\RefreshCrossVenueCandidatePool;
use Domains\CapitalMarkets\Application\Service\CrossVenueActiveUniverse;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueDiscoverySnapshotRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
final readonly class RefreshCrossVenueCandidatePoolHandler
{
    public function __construct(
        private CrossVenueActiveUniverse $universe,
        private CrossVenueCatalogDiscovery $discovery,
        private CrossVenueDiscoverySnapshotRepository $snapshots,
        private CrossVenuePoolCandidateRepository $pool,
        private LoggerInterface $logger,
        private CapitalMarketsAccessControlInterface $access,
        #[Autowire('%kernel.project_dir%/../resources/capital-markets/universes/binance-bstocks-2026-09-27.csv')]
        private string $file,
    ) {}

    public function __invoke(RefreshCrossVenueCandidatePool $message): void
    {
        if ($message->organizationId === '' || strlen($message->organizationId) > 190 || $message->actorId < 1) {
            throw new \InvalidArgumentException('Invalid scheduled discovery tenant/actor.');
        }
        if (!$this->access->hasCapability($message->organizationId,$message->actorId,CapitalMarketsCapability::MarketDataManage->value)
            && !$this->access->hasCapability($message->organizationId,$message->actorId,CapitalMarketsCapability::Manage->value)) {
            throw new \RuntimeException('Scheduled cross-venue discovery actor lacks required capability.');
        }
        if (!$this->snapshots->reserveScan($message->organizationId, $message->actorId)) {
            $this->logger->info('Cross-venue discovery cooldown active.', ['organization_id'=>$message->organizationId]);
            return;
        }
        $universe = $this->universe->load($message->organizationId,$this->file);
        $snapshot = $this->discovery->scan($message->organizationId,$universe['rows'],
            $universe['source_as_of'],$universe['origin']);
        $this->snapshots->record($message->organizationId, $message->actorId, $snapshot);
        $count = $this->pool->reconcile($message->organizationId, $snapshot);
        $this->logger->info('Cross-venue candidate observations saved.', [
            'organization_id'=>$message->organizationId,
            'observations'=>$count,'source_health'=>$snapshot['source_health'],
        ]);
    }
}
