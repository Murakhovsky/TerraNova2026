<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;

interface ReferenceDataAdapterInterface extends MarketDataAdapterInterface
{
    public function getReferenceQuote(MarketSourceDescriptor $source,VenueInstrument $instrument):MarketDataBatch;

    /** @param callable(RawMarketEvent):void $consumer */
    public function subscribeReferenceQuote(MarketSourceDescriptor $source,MarketSubscription $subscription,callable $consumer):void;

    public function getReferenceHistory(MarketSourceDescriptor $source,VenueInstrument $instrument,DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null):MarketDataBatch;
}
