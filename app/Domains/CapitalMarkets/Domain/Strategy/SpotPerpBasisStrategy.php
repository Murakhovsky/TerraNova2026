<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Strategy;

use Domains\CapitalMarkets\Domain\Opportunity\OpportunityType;

final class SpotPerpBasisStrategy
{
    /** @param array<string,mixed> $configuration */
    public static function version(int $version,array $configuration):StrategyDefinition
    {
        return new StrategyDefinition('SpotPerpBasisStrategy',$version,OpportunityType::SpotPerpBasis,$configuration);
    }
}
