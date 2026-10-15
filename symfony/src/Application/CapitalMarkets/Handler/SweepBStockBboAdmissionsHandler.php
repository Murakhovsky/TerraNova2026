<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Handler;

use App\Application\CapitalMarkets\Command\SweepBStockBboAdmissions;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueBboAdmissionFence;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepBStockBboAdmissionsHandler
{
    public function __construct(
        private CrossVenueBboAdmissionFence $fence,
        private CapitalMarketsAccessControlInterface $access,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SweepBStockBboAdmissions $message):void
    {
        $org=$message->organizationId;
        if ($org==='' || strlen($org)>190 || $message->actorId<1) {
            throw new RuntimeException('BBO fence requires explicit tenant and positive actor.');
        }
        if (!$this->access->hasCapability($org,$message->actorId,CapitalMarketsCapability::MarketDataManage->value)
            && !$this->access->hasCapability($org,$message->actorId,CapitalMarketsCapability::Manage->value)) {
            throw new RuntimeException('BBO fence service actor lacks MarketDataManage.');
        }
        $disabled=$this->fence->disableUnapproved($org);
        if ($disabled>0) {
            $this->logger->warning('BBO subscriptions disabled by expired/revoked review fence.',[
                'organization_id'=>$org,'disabled_subscriptions'=>$disabled,
            ]);
        } else {
            $this->logger->info('BBO review fence sweep completed.',[
                'organization_id'=>$org,'disabled_subscriptions'=>0,
            ]);
        }
    }
}
