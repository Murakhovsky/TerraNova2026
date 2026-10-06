<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;

interface MarketQualityMetricRepositoryInterface
{
    public function append(
        string $organizationId,
        CanonicalMarketEvent $event,
        MarketDataQualityAssessment $assessment,
        DateTimeImmutable $recordedAt,
    ):void;
}
