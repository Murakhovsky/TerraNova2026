<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Agent;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\CapitalRiskService;
use Domains\CapitalMarkets\Application\Service\MarketDataAdministrationService;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;

/**
 * Read-only, tenant-scoped evidence projection. No LLM is allowed to
 * query arbitrary organizations or mutate risk/ledger/execution state.
 */
final readonly class CapitalMarketsSpecialistContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(
        private MarketDataAdministrationService $marketData,
        private CapitalRiskService $capitalRisk,
        private CapitalMarketsTradingRepositoryInterface $trading,
    ){}

    public function build(AgentInvocation $invocation):array
    {
        $org=$invocation->organizationId;
        $name=$invocation->agentName;
        $base=[
            'subject'=>['type'=>$invocation->subjectType,'id'=>$invocation->subjectId],
            'prior_stage_evidence'=>array_slice($invocation->contextReferences,0,8),
            'authority'=>[
                'allow_order_execution'=>false,
                'allow_live_trading'=>false,
                'allow_ledger_mutation'=>false,
                'allow_limit_override'=>false,
                'allow_self_approval'=>false,
                'output_is_recommendation_only'=>true,
            ],
        ];
        if($name==='capital_markets_market_intelligence'){
            $market=$this->marketData->dashboard($org);
            return $base+['market'=>[
                'sources'=>array_slice($market['sources']??[],0,50),
                'states'=>array_slice($market['states']??[],0,100),
                'reference_states'=>array_slice($market['reference_states']??[],0,100),
            ]];
        }
        if($name==='capital_markets_opportunity'){
            return $base+[
                'opportunities'=>$this->trading->listOpportunities($org,100),
                'market'=>$this->boundedMarket($org),
            ];
        }
        if($name==='capital_markets_risk'){
            $portfolio=$this->capitalRisk->workspace($org);
            return $base+[
                'risk'=>$portfolio['risk']??null,
                'risk_envelope'=>$portfolio['risk_envelope']??null,
                'exposure'=>$portfolio['exposure']??null,
                'capital_state'=>$portfolio['capital_state']??null,
                'opportunities'=>array_slice($portfolio['opportunities']??[],0,50),
            ];
        }
        if($name==='capital_markets_execution'){
            $portfolio=$this->capitalRisk->workspace($org);
            return $base+[
                'risk_envelope'=>$portfolio['risk_envelope']??null,
                'allocation_plan'=>$portfolio['allocation_plan']??null,
                'capital_state'=>$portfolio['capital_state']??null,
                'executions'=>$this->trading->listExecutions($org,50),
                'reservations'=>$this->trading->listCapitalReservations($org,null,50),
            ];
        }
        if($name==='capital_markets_orchestrator'){
            $portfolio=$this->capitalRisk->workspace($org);
            return $base+[
                'risk'=>$portfolio['risk']??null,
                'performance'=>$portfolio['performance']??null,
                'positions'=>array_slice($portfolio['positions']??[],0,50),
                'executions'=>$this->trading->listExecutions($org,100),
            ];
        }
        throw new \RuntimeException('Unsupported Capital Markets specialist context.');
    }

    private function boundedMarket(string $organizationId):array
    {
        $market=$this->marketData->dashboard($organizationId);
        return [
            'states'=>array_slice($market['states']??[],0,50),
            'reference_states'=>array_slice($market['reference_states']??[],0,50),
        ];
    }
}
