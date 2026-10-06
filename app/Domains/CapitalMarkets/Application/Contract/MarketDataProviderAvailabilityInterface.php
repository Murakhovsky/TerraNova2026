<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;

interface MarketDataProviderAvailabilityInterface
{
    public function enabled(string $organizationId,MarketSourceDescriptor $source):bool;
}
