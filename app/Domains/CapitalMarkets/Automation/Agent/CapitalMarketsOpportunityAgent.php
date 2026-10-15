<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Agent;

use Kernel\Agent\AgentDefinition;

final class CapitalMarketsOpportunityAgent
{
    public const NAME='capital_markets_opportunity';

    public static function definition():AgentDefinition
    {
        return CapitalMarketsSpecialistDefinition::create(self::NAME);
    }
}
