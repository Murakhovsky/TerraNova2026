<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\PerpetualProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;

interface DerivativeMarketDataAdapterInterface extends MarketDataAdapterInterface
{
    public function getPerpetualProfile(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):PerpetualProfile;

    /** @return list<FundingRateObservation> */
    public function getFundingHistory(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit=200,
    ):array;
}
