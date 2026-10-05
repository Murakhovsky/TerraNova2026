<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;

interface CapitalMarketsEventPublisherInterface
{
    public function publish(
        AbstractCapitalMarketsEvent $event,
        ?string $correlationId=null,
        ?int $actorId=null,
    ):void;
}
