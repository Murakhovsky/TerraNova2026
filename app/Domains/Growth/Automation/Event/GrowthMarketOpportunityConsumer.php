<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Module\ActiveModuleResolver;

final readonly class GrowthMarketOpportunityConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private GrowthMarketDiscoveryBoundary $market,
        private ActiveModuleResolver $modules,
    ) {}

    public function consumerName():string{return 'growth.market-opportunity.v1';}

    public function handle(DomainEvent $event):void
    {
        if($event->type!==GrowthEventType::SIGNAL_DETECTED)return;
        if(!$this->modules->isEnabled($event->organizationId,'growth'))return;
        if((string)($event->payload['subject_type']??'')!=='account')return;
        $accountId=trim((string)($event->payload['subject_id']??''));
        if($accountId==='')return;
        $this->market->considerSignal(
            $event->organizationId,$accountId,$event->aggregateId,$event->metadata->correlationId
        );
    }
}
