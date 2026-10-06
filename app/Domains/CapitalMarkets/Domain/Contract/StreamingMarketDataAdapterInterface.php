<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface StreamingMarketDataAdapterInterface extends MarketDataAdapterInterface
{
    public function connect(string $organizationId,MarketSourceDescriptor $source):void;
    public function disconnect(string $organizationId,MarketSourceDescriptor $source):void;

    public function subscribe(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketSubscription $subscription,
        MarketDataInstrumentTarget $target,
    ):void;

    public function unsubscribe(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketSubscription $subscription,
        MarketDataInstrumentTarget $target,
    ):void;

    public function isConnected(string $organizationId,MarketSourceDescriptor $source):bool;
    public function getConnectionState(string $organizationId,MarketSourceDescriptor $source):MarketConnectionState;

    /** @param callable(RawMarketEvent):void $consumer */
    public function consume(
        string $organizationId,
        MarketSourceDescriptor $source,
        callable $consumer,
        ?int $maximumMessages=null,
    ):void;
}
