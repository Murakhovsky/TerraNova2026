<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsExecutionAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsMarketIntelligenceAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOpportunityAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOrchestratorAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsRiskAgent;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Service\AgentRuntime;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use RuntimeException;

/**
 * Observe -> Research -> Detect -> Assess -> Allocate -> Execute
 * -> Measure -> Learn, with deterministic safety gates and persisted AgentRuns.
 *
 * The Execute step is a review ONLY. No orders, allocations, reservations,
 * Ledger mutations, strategy promotions or live-trading writes are issued.
 * The existing approved paper execution services remain authoritative.
 */
final readonly class CapitalMarketsRuntimeOrchestrator
{
    public function __construct(
        private AgentRuntime $runtime,
        private DomainModuleRegistry $domains,
        private ActiveModuleResolver $modules,
        private CapitalMarketsResearchAgentService $researchAgent,
        private CapitalMarketsPortfolioAgentService $portfolioAgent,
        private CapitalMarketsRuntimeEvidenceGate $gates,
        private MarketDataAdministrationService $marketData,
        private CapitalRiskService $capitalRisk,
        private CapitalMarketsTradingRepositoryInterface $trading,
    ){}

    /** @return array<string,mixed> */
    public function run(
        string $organizationId,
        string $opportunityId,
        string $question,
        string $correlationId,
    ):array{
        if(trim($organizationId)===''||trim($opportunityId)===''
            ||trim($question)===''||strlen($question)>4000
            ||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{2,96}$/D',$correlationId)){
            throw new \InvalidArgumentException('Invalid Capital Markets agent cycle input.');
        }
        if(!$this->modules->isEnabled($organizationId,'capital_markets')){
            throw new RuntimeException('Capital Markets module is disabled.');
        }
        $stages=[];
        $summaries=[];
        $observe=$this->specialist(
            CapitalMarketsMarketIntelligenceAgent::NAME,'Observe',
            $organizationId,$opportunityId,$question,$correlationId,$summaries
        );
        $this->append($stages,$summaries,$observe);
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_OBSERVE_BLOCKED');
        }
        $problem=$this->gates->observation($this->marketData->dashboard($organizationId));
        if($problem!==null)return $this->stopped($correlationId,$stages,$problem);

        $research=$this->researchAgent->run(
            $organizationId,'opportunity',$opportunityId,$question,
            $correlationId.':research',
        );
        $this->append($stages,$summaries,$this->serviceStage('Research',$research));
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_RESEARCH_BLOCKED');
        }

        $detect=$this->specialist(
            CapitalMarketsOpportunityAgent::NAME,'Detect',
            $organizationId,$opportunityId,$question,$correlationId,$summaries
        );
        $this->append($stages,$summaries,$detect);
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_DETECT_BLOCKED');
        }
        $problem=$this->gates->opportunity(
            $this->trading->getOpportunity($organizationId,$opportunityId)
        );
        if($problem!==null)return $this->stopped($correlationId,$stages,$problem);

        $assess=$this->specialist(
            CapitalMarketsRiskAgent::NAME,'Assess',
            $organizationId,$opportunityId,$question,$correlationId,$summaries
        );
        $this->append($stages,$summaries,$assess);
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_ASSESS_BLOCKED');
        }
        $problem=$this->gates->risk($this->capitalRisk->workspace($organizationId));
        if($problem!==null)return $this->stopped($correlationId,$stages,$problem);

        $allocation=$this->portfolioAgent->run(
            $organizationId,'opportunity',$opportunityId,$question,
            $correlationId.':allocate',
        );
        $this->append($stages,$summaries,$this->serviceStage('Allocate',$allocation));
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_ALLOCATE_BLOCKED');
        }

        $execution=$this->specialist(
            CapitalMarketsExecutionAgent::NAME,'Execute',
            $organizationId,$opportunityId,
            $question.' Paper execution review ONLY: human authorization and deterministic services remain mandatory.',
            $correlationId,$summaries
        );
        $this->append($stages,$summaries,$execution);
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_EXECUTE_BLOCKED');
        }

        // The LLM cannot satisfy or override this gate. The only accepted
        // evidence is an already-persisted FINALIZED paper execution.
        $problem=$this->gates->measuredExecution(
            $this->trading->getExecutionForOpportunity($organizationId,$opportunityId),
            $opportunityId,
        );
        if($problem!==null){
            return $this->stopped($correlationId,$stages,$problem,'AWAITING_EXECUTION_EVIDENCE');
        }

        $measure=$this->specialist(
            CapitalMarketsOrchestratorAgent::NAME,'Measure',
            $organizationId,$opportunityId,$question,$correlationId,$summaries
        );
        $this->append($stages,$summaries,$measure);
        if(end($stages)['decision']==='BLOCKED'){
            return $this->stopped($correlationId,$stages,'AGENT_MEASURE_BLOCKED');
        }

        // Learning uses the existing Research Agent and its governed tools.
        // No automatic strategy promotion; knowledge can only be proposed.
        $learn=$this->researchAgent->run(
            $organizationId,'opportunity',$opportunityId,
            'Learn from measured paper execution and previous stage evidence. '.$question,
            $correlationId.':learn',
        );
        $this->append($stages,$summaries,$this->serviceStage('Learn',$learn));
        return [
            'status'=>'REVIEW_REQUIRED','correlation_id'=>$correlationId,
            'stage_count'=>count($stages),'stages'=>$stages,
            'next_action'=>'HUMAN_REVIEW_RESEARCH_AND_PERFORMANCE',
            'live_trading_enabled'=>false,
        ];
    }

    /** @param list<array<string,mixed>> $prior @return array<string,mixed> */
    private function specialist(
        string $agentName,string $stage,string $organizationId,
        string $opportunityId,string $question,string $correlationId,array $prior,
    ):array{
        if($this->domains->ownerOfAgent($agentName)!=='capital_markets'){
            throw new RuntimeException('Capital Markets specialist is not registered to its domain.');
        }
        $agent=$this->domains->agent($agentName);
        $execution=$this->runtime->run($agent,new AgentInvocation(
            $organizationId,'opportunity',$opportunityId,
            mb_substr($question,0,4000),
            $correlationId.':'.strtolower($stage),
            array_slice($prior,-8),
            $agentName,
        ));
        return [
            'stage'=>$stage,'agent'=>$agentName,'run_id'=>$execution->runId,
            'decision'=>$execution->result->decision,
            'reason'=>mb_substr($execution->result->reason,0,1000),
            'confidence'=>$execution->result->confidence,
            'action_count'=>count($execution->proposals),
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function serviceStage(string $stage,array $result):array
    {
        return [
            'stage'=>$stage,
            'agent'=>$stage==='Allocate'?'capital_markets_portfolio':'capital_markets_research',
            'run_id'=>(string)($result['final_run_id']??''),
            'decision'=>(string)($result['decision']??'BLOCKED'),
            'reason'=>mb_substr((string)($result['reason']??''),0,1000),
            'confidence'=>(float)($result['confidence']??0),
            'tool_count'=>count($result['tool_results']??[]),
            'action_count'=>0,
        ];
    }

    /** @param list<array<string,mixed>> $stages @param list<array<string,mixed>> $prior @param array<string,mixed> $stage */
    private function append(array &$stages,array &$prior,array $stage):void
    {
        if(($stage['action_count']??0)!==0){
            throw new RuntimeException('Capital Markets agent emitted unauthorized business actions.');
        }
        $stages[]=$stage;
        $prior[]=[
            'stage'=>$stage['stage'],'run_id'=>$stage['run_id'],
            'decision'=>$stage['decision'],
            'reason'=>$stage['reason'],
        ];
    }

    /** @param list<array<string,mixed>> $stages @return array<string,mixed> */
    private function stopped(
        string $correlationId,array $stages,string $reason,
        string $status='BLOCKED',
    ):array{
        return [
            'status'=>$status,'correlation_id'=>$correlationId,
            'stage_count'=>count($stages),'stages'=>$stages,
            'next_action'=>$reason,'live_trading_enabled'=>false,
        ];
    }
}
