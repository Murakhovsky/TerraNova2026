<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface StreamingMarketDataAdapterInterface extends MarketDataAdapterInterface
{
    public function connect(MarketSourceDescriptor $source):void;
    public function disconnect(MarketSourceDescriptor $source):void;
    public function subscribe(MarketSourceDescriptor $source,MarketSubscription $subscription):void;
    public function unsubscribe(MarketSourceDescriptor $source,MarketSubscription $subscription):void;
    public function isConnected(MarketSourceDescriptor $source):bool;
    public function getConnectionState(MarketSourceDescriptor $source):MarketConnectionState;

    /**
     * Reads provider messages and invokes consumer for each raw event.
     *
     * @param callable(RawMarketEvent):void $consumer
     */
    public function consume(MarketSourceDescriptor $source,callable $consumer,?int $maximumMessages=null):void;
}
