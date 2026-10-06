<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface ReferenceDataAdapterInterface extends MarketDataAdapterInterface
{
    public function getReferenceQuote(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):MarketDataBatch;

    /** @param callable(RawMarketEvent):void $consumer */
    public function subscribeReferenceQuote(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketSubscription $subscription,
        MarketDataInstrumentTarget $target,
        callable $consumer,
    ):void;

    public function getReferenceHistory(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null,
    ):MarketDataBatch;
}
