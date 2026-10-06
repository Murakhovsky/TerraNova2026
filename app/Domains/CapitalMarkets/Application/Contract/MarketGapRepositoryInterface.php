<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketDataGap;
use Domains\CapitalMarkets\Domain\MarketData\MarketGapStatus;

interface MarketGapRepositoryInterface
{
    public function save(string $organizationId,MarketDataGap $gap):void;

    /** @return list<MarketDataGap> */
    public function list(string $organizationId,?MarketGapStatus $status=null,int $limit=200):array;
}
