<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Event\ResearchLifecycleEvent;
use Kernel\Event\EventBus;

final readonly class ResearchEventPublisher
{
    public function __construct(private EventBus $events){}

    /** @param array<string,mixed> $payload */
    public function publish(string $organizationId,string $type,string $aggregateId,array $payload=[]):void
    {
        $eventId='cm-research-'.substr(hash('sha256',$organizationId.'|'.$type.'|'.$aggregateId.'|'.json_encode($payload,JSON_THROW_ON_ERROR).'|'.microtime(true)),0,36);
        $this->events->publish(new ResearchLifecycleEvent(
            $type,$eventId,new DateTimeImmutable('now'),$organizationId,$aggregateId,$payload
        ));
    }
}
