<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Event\ResearchLifecycleEvent;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;

final readonly class ResearchEventPublisher
{
    public function __construct(private EventBus $events){}

    /** @param array<string,mixed> $payload */
    public function publish(string $organizationId,string $type,string $aggregateId,array $payload=[]):void
    {
        $occurredAt=new DateTimeImmutable('now');
        $eventId='cm-research-'.substr(hash(
            'sha256',
            $organizationId.'|'.$type.'|'.$aggregateId.'|'.json_encode($payload,JSON_THROW_ON_ERROR).'|'.$occurredAt->format('U.u')
        ),0,36);

        // Validate the Capital Markets research-event vocabulary at the Domain boundary,
        // then adapt it to the canonical durable Kernel Event envelope.
        $researchEvent=new ResearchLifecycleEvent($type,$eventId,$occurredAt,$organizationId,$aggregateId,$payload);

        $this->events->publish(new DomainEvent(
            id:$researchEvent->eventId(),
            organizationId:$organizationId,
            type:$researchEvent->eventName(),
            aggregateType:'capital_markets.research',
            aggregateId:$aggregateId,
            payload:$payload,
            metadata:new EventMetadata(
                correlationId:$eventId,
                causationId:null,
                actorType:'SYSTEM',
                actorId:'capital_markets_research',
                schemaVersion:1,
            ),
            occurredAt:$occurredAt,
        ));
    }
}
