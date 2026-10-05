<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Integration;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;
use Platform\Integration\Contract\IntegrationOutboxInterface;

final readonly class OutboxCapitalMarketsEventPublisher implements CapitalMarketsEventPublisherInterface
{
    public function __construct(private IntegrationOutboxInterface $outbox){}

    public function publish(AbstractCapitalMarketsEvent $event):void
    {
        $this->outbox->enqueue(
            'capital_markets',
            $event->eventName(),
            'capital_markets_domain_event',
            null,
            $event->envelope(),
            $event->eventId(),
        );
    }
}
