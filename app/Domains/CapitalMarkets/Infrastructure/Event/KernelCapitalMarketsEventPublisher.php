<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Event;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;
use Kernel\Event\DomainEvent as KernelDomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;

final readonly class KernelCapitalMarketsEventPublisher implements CapitalMarketsEventPublisherInterface
{
    public function __construct(private EventBus $events){}

    public function publish(AbstractCapitalMarketsEvent $event):void
    {
        $this->events->publish(new KernelDomainEvent(
            id:$event->eventId(),
            organizationId:$event->organizationId,
            type:$event->eventName(),
            aggregateType:$this->aggregateType($event->eventName()),
            aggregateId:$event->aggregateId,
            payload:$event->payload,
            metadata:new EventMetadata(
                correlationId:$event->eventId(),
                causationId:null,
                actorType:'SYSTEM',
                actorId:'capital_markets',
                schemaVersion:$event->schemaVersion,
            ),
            occurredAt:$event->occurredAt(),
        ));
    }

    private function aggregateType(string $eventType):string
    {
        if(str_starts_with($eventType,'capital_markets.instrument.')){
            return 'capital_markets.instrument';
        }
        if(str_starts_with($eventType,'capital_markets.relationship.')){
            return 'capital_markets.relationship';
        }
        if(str_starts_with($eventType,'capital_markets.venue_instrument.')){
            return 'capital_markets.venue_instrument';
        }
        return 'capital_markets.venue';
    }
}
