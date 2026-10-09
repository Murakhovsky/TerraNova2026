<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Bootstrap;

use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsResearchAgent;
use Domains\CapitalMarkets\Automation\Action\RecordValidatedResearchResultHandler;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsPortfolioAgent;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\Contract\AgentProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class CapitalMarketsDomainModule implements DomainModuleInterface,AgentProvidingModuleInterface,ActionOwningModuleInterface,PolicyProvidingModuleInterface,BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private AgentContextBuilderInterface $researchAgentContext,private AgentContextBuilderInterface $portfolioAgentContext,private RecordValidatedResearchResultHandler $researchResultHandler){}

    public function name():string{return 'capital_markets';}

    public function actionTypes():array { return [RecordValidatedResearchResultHandler::TYPE]; }

    public function actionHandlers():array { return [$this->researchResultHandler]; }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array
    {
        $id = $organizationId === 'default' ? 'cm-research-reviewed-result-v1'
            : substr(hash('sha256', $organizationId . ':cm-research-reviewed-result-v1'), 0, 32);
        return [new ActionPolicy(
            $id, $organizationId, RecordValidatedResearchResultHandler::TYPE,
            [], PolicyDecision::ApprovalRequired, 10,
            'Research validated result requires independent approval',
        )];
    }

    /** @return list<ActionPolicy> */
    public function bootstrapPolicies(): array { return $this->policies('default'); }


    public function agents():array
    {
        return [CapitalMarketsResearchAgent::NAME=>CapitalMarketsResearchAgent::definition(),CapitalMarketsPortfolioAgent::NAME=>CapitalMarketsPortfolioAgent::definition()];
    }

    public function agentContextBuilders():array
    {
        return [CapitalMarketsResearchAgent::NAME=>$this->researchAgentContext,CapitalMarketsPortfolioAgent::NAME=>$this->portfolioAgentContext];
    }
}
