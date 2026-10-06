<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface RawMarketEventRepositoryInterface
{
    public function append(string $organizationId,RawMarketEvent $event):bool;

    /** @return list<RawMarketEvent> */
    public function range(string $organizationId,MarketSourceId $sourceId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array;
}
