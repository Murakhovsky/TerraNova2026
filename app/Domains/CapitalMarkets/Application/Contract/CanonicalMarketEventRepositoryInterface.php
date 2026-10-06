<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;

interface CanonicalMarketEventRepositoryInterface
{
    public function append(string $organizationId,CanonicalMarketEvent $event):bool;

    /** @return list<CanonicalMarketEvent> */
    public function history(string $organizationId,InstrumentId $instrumentId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array;
}
