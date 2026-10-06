<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Opportunity;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;
final readonly class SpreadDetectorConfig extends ValueObject
{
    public function __construct(
        public string $version,
        public Decimal $minimumGrossEdgeBps,
        public Decimal $minimumExpectedNetEdgeBps,
        public Decimal $minimumExpectedPnl,
        public int $maximumSnapshotAgeMs,
        public int $maximumSnapshotSkewMs,
        public int $minimumDataQuality,
        public Decimal $maximumSlippageBps,
        public int $minimumOpportunityTtlMs,
        public Decimal $economicEquivalenceThreshold,
    ){
        if($version===''||$maximumSnapshotAgeMs<1||$maximumSnapshotSkewMs<0||$minimumDataQuality<0||$minimumDataQuality>100||$minimumOpportunityTtlMs<1){
            throw new InvalidArgumentException('Invalid spread detector configuration.');
        }
    }
}
