<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;

interface HistoricalMarketDataAdapterInterface extends MarketDataAdapterInterface
{
    public function getTrades(MarketSourceDescriptor $source,VenueInstrument $instrument,DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null):MarketDataBatch;
    public function getQuotes(MarketSourceDescriptor $source,VenueInstrument $instrument,DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null):MarketDataBatch;
    public function getCandles(MarketSourceDescriptor $source,VenueInstrument $instrument,DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null):MarketDataBatch;
}
