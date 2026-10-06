<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;

final class CrossVenueFundingStrategy
{
    /** @param array<string,mixed> $configuration */
    public static function version(int $version,array $configuration):StrategyDefinition
    {
        return new StrategyDefinition('CrossVenueFundingStrategy',$version,OpportunityType::CrossVenueFunding,$configuration);
    }
}
