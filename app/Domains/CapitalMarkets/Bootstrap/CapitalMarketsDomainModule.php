<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Bootstrap;

use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsResearchAgent;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class CapitalMarketsDomainModule implements DomainModuleInterface,AgentProvidingModuleInterface
{
    public function __construct(private AgentContextBuilderInterface $researchAgentContext){}

    public function name():string{return 'capital_markets';}

    public function agents():array
    {
        return [CapitalMarketsResearchAgent::NAME=>CapitalMarketsResearchAgent::definition()];
    }

    public function agentContextBuilders():array
    {
        return [CapitalMarketsResearchAgent::NAME=>$this->researchAgentContext];
    }
}
