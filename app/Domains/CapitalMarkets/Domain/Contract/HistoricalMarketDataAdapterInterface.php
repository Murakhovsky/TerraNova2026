<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;

interface HistoricalMarketDataAdapterInterface extends MarketDataAdapterInterface
{
    public function getTrades(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null,
    ):MarketDataBatch;

    public function getQuotes(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null,
    ):MarketDataBatch;

    public function getCandles(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,?string $cursor=null,
    ):MarketDataBatch;
}
