<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;

interface MarketDataIngestionInterface
{
    public function ingest(string $organizationId,RawMarketEvent $raw):MarketDataIngestionResult;
}
