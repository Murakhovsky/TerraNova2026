<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Bootstrap;

use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsResearchAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsPortfolioAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsMarketIntelligenceAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOpportunityAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsRiskAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsExecutionAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOrchestratorAgent;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class CapitalMarketsDomainModule implements DomainModuleInterface,AgentProvidingModuleInterface
{
    public function __construct(private AgentContextBuilderInterface $researchAgentContext,private AgentContextBuilderInterface $portfolioAgentContext,private AgentContextBuilderInterface $specialistAgentContext){}

    public function name():string{return 'capital_markets';}

    public function agents():array
    {
        return [
            CapitalMarketsResearchAgent::NAME=>CapitalMarketsResearchAgent::definition(),
            CapitalMarketsPortfolioAgent::NAME=>CapitalMarketsPortfolioAgent::definition(),
            CapitalMarketsMarketIntelligenceAgent::NAME=>CapitalMarketsMarketIntelligenceAgent::definition(),
            CapitalMarketsOpportunityAgent::NAME=>CapitalMarketsOpportunityAgent::definition(),
            CapitalMarketsRiskAgent::NAME=>CapitalMarketsRiskAgent::definition(),
            CapitalMarketsExecutionAgent::NAME=>CapitalMarketsExecutionAgent::definition(),
            CapitalMarketsOrchestratorAgent::NAME=>CapitalMarketsOrchestratorAgent::definition(),
        ];
    }

    public function agentContextBuilders():array
    {
        return [
            CapitalMarketsResearchAgent::NAME=>$this->researchAgentContext,
            CapitalMarketsPortfolioAgent::NAME=>$this->portfolioAgentContext,
            CapitalMarketsMarketIntelligenceAgent::NAME=>$this->specialistAgentContext,
            CapitalMarketsOpportunityAgent::NAME=>$this->specialistAgentContext,
            CapitalMarketsRiskAgent::NAME=>$this->specialistAgentContext,
            CapitalMarketsExecutionAgent::NAME=>$this->specialistAgentContext,
            CapitalMarketsOrchestratorAgent::NAME=>$this->specialistAgentContext,
        ];
    }
}
