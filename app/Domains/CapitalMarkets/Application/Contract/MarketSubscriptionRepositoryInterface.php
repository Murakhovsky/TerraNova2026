<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;

interface MarketSubscriptionRepositoryInterface
{
    public function save(string $organizationId,MarketSubscription $subscription):void;

    /** @return list<MarketSubscription> */
    public function forSource(string $organizationId,MarketSourceId $sourceId,bool $activeOnly=false):array;
}
