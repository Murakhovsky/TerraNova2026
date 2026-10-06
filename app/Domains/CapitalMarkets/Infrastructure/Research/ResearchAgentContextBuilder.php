<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Research;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RelativeValueResearchRepositoryInterface;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Contract\AgentContextBuilderInterface;

final readonly class ResearchAgentContextBuilder implements AgentContextBuilderInterface
{
    public function __construct(
        private ResearchLabRepositoryInterface $research,
        private RelativeValueResearchRepositoryInterface $relativeValue,
    ){}

    public function build(AgentInvocation $invocation):array
    {
        $org=$invocation->organizationId;
        $context=[
            'research'=>[
                'hypotheses'=>$this->research->listHypotheses($org,250),
                'experiments'=>$this->research->listExperiments($org,null,250),
                'knowledge'=>$this->research->listKnowledge($org,250),
            ],
            'relative_value'=>[
                'funding_observations'=>$this->relativeValue->listFundingObservations($org,null,null,500),
                'funding_settlements'=>$this->relativeValue->listFundingSettlements($org,null,500),
            ],
            'subject'=>[
                'type'=>$invocation->subjectType,
                'id'=>$invocation->subjectId,
            ],
            'research_rules'=>[
                'ai_proposes_engine_tests_data_decides'=>true,
                'live_trading_authority'=>false,
                'risk_limit_authority'=>false,
                'completed_result_edit_authority'=>false,
                'dataset_mutation_after_run'=>false,
                'strategy_version_mutation_after_run'=>false,
            ],
        ];

        if($invocation->subjectType==='market_pair' && $invocation->subjectId!==''){
            $context['relative_value']['basis_observations']=$this->relativeValue->listBasisObservations($org,$invocation->subjectId,500);
        }

        return $context;
    }
}
