<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface MarketDataDecoderInterface
{
    public function adapterType():string;
    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent;
}
