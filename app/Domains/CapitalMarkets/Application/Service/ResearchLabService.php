<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use Domains\CapitalMarkets\Domain\Research\StrategyScorecardEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\ResearchDuplicateDetector;
use Domains\CapitalMarkets\Domain\Research\HypothesisLifecyclePolicy;
use Domains\CapitalMarkets\Domain\Research\ExperimentLifecyclePolicy;
use Domains\CapitalMarkets\Domain\Research\StrategyDemotionPolicy;
use InvalidArgumentException;

final readonly class ResearchLabService
{
    public function __construct(
        private ResearchLabRepositoryInterface $repository,
        private StrategyPromotionGate $promotionGate,
        private StrategyScorecardEngine $scorecards,
        private ResearchIsolationPolicy $isolation,
        private ReplayDataGuard $replayGuard,
        private ResearchDuplicateDetector $duplicates,
        private HypothesisLifecyclePolicy $hypothesisLifecycle,
        private ExperimentLifecyclePolicy $experimentLifecycle,
        private ResearchEventPublisher $events,
        private StrategyDemotionPolicy $demotionPolicy,
        private ResearchTelemetry $telemetry,
    ){}

    public function createHypothesis(string $organizationId,array $record):array
    {
        foreach(['hypothesis_id','code','title','economic_reason','edge_source','status','priority'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if($this->repository->getHypothesis($organizationId,(string)$record['hypothesis_id'])!==null){
            throw new InvalidArgumentException('Hypothesis id already exists; create a new revision instead.');
        }
        $record['status']=strtoupper(trim((string)$record['status']));
        $record['edge_source']=strtoupper(trim((string)$record['edge_source']));
        if($record['status']==='READY_FOR_RESEARCH')$this->assertResearchReady($record);
        if(!in_array((string)$record['status'],['IDEA','DRAFT'],true) && (string)$record['edge_source']==='UNKNOWN'){
            throw new InvalidArgumentException('UNKNOWN edge source is allowed only for IDEA/DRAFT.');
        }
        if(!(bool)($record['allow_duplicate']??false)){
            $matches=$this->duplicates->find($record,$this->repository->listHypotheses($organizationId,500));
            if($matches!==[]){
                throw new InvalidArgumentException('Potential duplicate research hypothesis: '.json_encode(array_slice($matches,0,3),JSON_THROW_ON_ERROR));
            }
        }
        unset($record['allow_duplicate']);
        $record['revision']=1;
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveHypothesis($organizationId,$record);
        $this->telemetry->metric($organizationId,'research_hypotheses_total');
        $this->events->publish($organizationId,'capital_markets.research.hypothesis_created.v1',(string)$record['hypothesis_id'],[
            'revision'=>$record['revision'],'status'=>$record['status'],'code'=>$record['code']
        ]);
        return $record;
    }

    public function reviseHypothesis(string $organizationId,string $hypothesisId,array $changes):array
    {
        $current=$this->repository->getHypothesis($organizationId,$hypothesisId);
        if($current===null)throw new InvalidArgumentException('Research hypothesis not found.');

        foreach(['hypothesis_id','revision','created_at','created_by'] as $immutable){
            unset($changes[$immutable]);
        }

        $next=array_replace($current,$changes);
        $nextStatus=strtoupper(trim((string)($next['status']??'')));
        $currentStatus=strtoupper(trim((string)($current['status']??'')));
        $next['status']=$nextStatus;
        $next['edge_source']=strtoupper(trim((string)($next['edge_source']??'UNKNOWN')));
        if($nextStatus!==$currentStatus){
            $this->hypothesisLifecycle->assertTransition($currentStatus,$nextStatus);
        }

        if($nextStatus==='READY_FOR_RESEARCH')$this->assertResearchReady($next);
        if(!in_array($nextStatus,['IDEA','DRAFT'],true) && $next['edge_source']==='UNKNOWN'){
            throw new InvalidArgumentException('UNKNOWN edge source is allowed only for IDEA/DRAFT.');
        }

        $next['hypothesis_id']=$hypothesisId;
        $next['revision']=max(1,(int)($current['revision']??1))+1;
        $next['created_at']=gmdate('Y-m-d H:i:s');
        $next['updated_at']=$next['created_at'];
        $next['supersedes_revision']=(int)($current['revision']??1);
        $this->repository->saveHypothesis($organizationId,$next);
        $type=$currentStatus==='REJECTED'&&$nextStatus!=='REJECTED'
            ? 'capital_markets.research.hypothesis_reopened.v1'
            : 'capital_markets.research.hypothesis_updated.v1';
        $this->events->publish($organizationId,$type,$hypothesisId,[
            'from_status'=>$currentStatus,'to_status'=>$nextStatus,
            'revision'=>$next['revision'],'supersedes_revision'=>$next['supersedes_revision']
        ]);
        return $next;
    }

    public function freezeDataset(string $organizationId,array $record):array
    {
        foreach(['dataset_id','name','from','to','data_sources','instrument_universe','venue_universe','data_types','schema_version','quality_status'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if((string)$record['quality_status']==='UNSUITABLE'){
            throw new InvalidArgumentException('UNSUITABLE dataset cannot be frozen for an experiment.');
        }
        $canonical=$record;
        unset($canonical['snapshot_hash'],$canonical['created_at']);
        $canonical=$this->canonicalize($canonical);
        $record['snapshot_hash']=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveDataset($organizationId,$record);
        return $record;
    }

    public function createStrategyVersion(string $organizationId,array $record):array
    {
        foreach(['strategy_version_id','strategy_id','version','logic_hash','status'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveStrategyVersion($organizationId,$record);
        $this->events->publish($organizationId,'capital_markets.research.strategy_version_created.v1',(string)$record['strategy_version_id'],[
            'strategy_id'=>$record['strategy_id'],'version'=>$record['version']
        ]);
        return $record;
    }

    public function createExperiment(string $organizationId,array $record):array
    {
        foreach(['experiment_id','hypothesis_id','dataset_id','strategy_version_id','experiment_type','status','success_criteria','failure_criteria'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if($this->repository->getHypothesis($organizationId,(string)$record['hypothesis_id'])===null){
            throw new InvalidArgumentException('Experiment requires an existing research hypothesis.');
        }
        if($this->repository->getDataset($organizationId,(string)$record['dataset_id'])===null){
            throw new InvalidArgumentException('Experiment requires a frozen dataset.');
        }
        if($this->repository->getStrategyVersion($organizationId,(string)$record['strategy_version_id'])===null){
            throw new InvalidArgumentException('Experiment requires an existing strategy version.');
        }
        $record['status']=strtoupper(trim((string)$record['status']));
        if(!in_array($record['status'],['DRAFT','QUEUED'],true)){
            throw new InvalidArgumentException('New experiment must start as DRAFT or QUEUED.');
        }
        $record['parameters']=$record['parameters']??[];
        $record['parameters_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['parameters']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['success_criteria_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['success_criteria']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['failure_criteria_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['failure_criteria']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveExperiment($organizationId,$record);
        $this->telemetry->metric($organizationId,'experiments_total');
        $this->events->publish($organizationId,'capital_markets.research.experiment_created.v1',(string)$record['experiment_id'],[
            'hypothesis_id'=>$record['hypothesis_id'],'strategy_version_id'=>$record['strategy_version_id'],'dataset_id'=>$record['dataset_id']
        ]);
        return $record;
    }

    public function transitionExperiment(string $organizationId,string $experimentId,string $to):array
    {
        $current=$this->repository->getExperiment($organizationId,$experimentId);
        if($current===null)throw new InvalidArgumentException('Research experiment not found.');
        $from=strtoupper(trim((string)($current['status']??'')));
        $to=strtoupper(trim($to));
        $this->experimentLifecycle->assertTransition($from,$to);
        $updatedAt=gmdate('Y-m-d H:i:s');
        if(!$this->repository->transitionExperimentStatus($organizationId,$experimentId,$from,$to,$updatedAt)){
            throw new InvalidArgumentException('Research experiment status changed concurrently.');
        }
        $current['status']=$to;
        $current['updated_at']=$updatedAt;
        $eventType=match($to){
            'RUNNING'=>'capital_markets.research.experiment_started.v1',
            'COMPLETED'=>'capital_markets.research.experiment_completed.v1',
            'INVALIDATED'=>'capital_markets.research.experiment_invalidated.v1',
            default=>null,
        };
        if($to==='FAILED')$this->telemetry->metric($organizationId,'experiments_failed_total');
        if($eventType!==null)$this->events->publish($organizationId,$eventType,$experimentId,['from_status'=>$from,'to_status'=>$to]);
        return $current;
    }

    public function recordResult(string $organizationId,array $record):array
    {
        foreach(['result_id','experiment_id','status','financial_metrics','risk_metrics','execution_metrics','data_quality','limitations'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if($this->repository->getExperiment($organizationId,(string)$record['experiment_id'])===null){
            throw new InvalidArgumentException('Result requires an existing experiment.');
        }
        if($this->repository->getResultForExperiment($organizationId,(string)$record['experiment_id'])!==null){
            throw new InvalidArgumentException('Completed experiment result is immutable.');
        }
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveResult($organizationId,$record);
        return $record;
    }

    public function recordBacktestRun(string $organizationId,array $record):array
    {
        foreach(['run_id','experiment_id','dataset_id','strategy_version_id','partition_name','status','reproducibility_fingerprint'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $configuration=(array)($record['configuration']??[]);
        $this->replayGuard->assertTransactionCosts($configuration);
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveBacktestRun($organizationId,$record);
        return $record;
    }

    public function recordOutOfSampleRun(string $organizationId,array $record,array $frozenExperiment):array
    {
        foreach(['run_id','experiment_id','dataset_id','strategy_version_id','status','from','to','parameters_hash','success_criteria_hash','failure_criteria_hash'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $this->isolation->assertOosFrozen($frozenExperiment,$record);
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveOutOfSampleRun($organizationId,$record);
        return $record;
    }

    public function createScorecard(string $organizationId,string $strategyVersionId,array $dimensions,array $weights,string $weightVersion):array
    {
        if($this->repository->getStrategyVersion($organizationId,$strategyVersionId)===null){
            throw new InvalidArgumentException('Scorecard requires an existing strategy version.');
        }
        $scorecard=$this->scorecards->calculate($strategyVersionId,$dimensions,$weights,$weightVersion);
        $record=[
            'scorecard_id'=>'scorecard-'.bin2hex(random_bytes(12)),
            'strategy_version_id'=>$strategyVersionId,
            'dimensions'=>$scorecard->dimensions,
            'weights'=>$scorecard->weights,
            'composite_score'=>$scorecard->compositeScore,
            'weight_version'=>$scorecard->weightVersion,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ];
        $this->repository->saveScorecard($organizationId,$record);
        $this->events->publish($organizationId,'capital_markets.research.strategy_scorecard_updated.v1',$strategyVersionId,[
            'scorecard_id'=>$record['scorecard_id'],'composite_score'=>$record['composite_score'],'weight_version'=>$record['weight_version']
        ]);
        return $record;
    }

    public function rejectHypothesis(string $organizationId,array $record):array
    {
        foreach(['rejection_id','hypothesis_id','reason','evidence','experiment_ids'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $allowed=['NO_EDGE','EDGE_TOO_SMALL','COSTS_DESTROY_EDGE','TOO_RISKY','INSUFFICIENT_CAPACITY','UNSTABLE','DATA_INSUFFICIENT','NOT_EXECUTABLE','REGIME_DEPENDENT','TECHNICALLY_INFEASIBLE'];
        if(!in_array((string)$record['reason'],$allowed,true))throw new InvalidArgumentException('Invalid rejection reason.');
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveRejectedHypothesis($organizationId,$record);
        $this->events->publish($organizationId,'capital_markets.research.hypothesis_rejected.v1',(string)$record['hypothesis_id'],[
            'rejection_id'=>$record['rejection_id'],'reason'=>$record['reason']
        ]);
        $current=$this->repository->getHypothesis($organizationId,(string)$record['hypothesis_id']);
        if($current!==null && strtoupper((string)($current['status']??''))!=='REJECTED'){
            $this->reviseHypothesis($organizationId,(string)$record['hypothesis_id'],['status'=>'REJECTED']);
        }
        return $record;
    }

    public function recordKnowledge(string $organizationId,array $record):array
    {
        foreach(['knowledge_id','knowledge_type','statement','experiment_ids','dataset_ids','strategy_version_ids','result_ids'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveKnowledge($organizationId,$record);
        return $record;
    }

    public function evaluatePromotion(
        string $organizationId,
        string $strategyVersionId,
        string $from,
        string $to,
        array $actual,
        array $policy,
        string $approver,
    ):array{
        $from=strtoupper(trim($from));
        $to=strtoupper(trim($to));
        if($this->repository->getStrategyVersion($organizationId,$strategyVersionId)===null){
            throw new InvalidArgumentException('Promotion requires an existing strategy version.');
        }
        $this->assertPromotionEvidence($organizationId,$strategyVersionId,$from,$to);
        if(trim((string)($policy['policy_version']??''))===''){
            throw new InvalidArgumentException('Promotion policy_version is required.');
        }
        $evaluation=$this->promotionGate->evaluate($from,$to,$actual,$policy);
        $record=[
            'decision_id'=>'promotion-'.bin2hex(random_bytes(12)),
            'strategy_version_id'=>$strategyVersionId,
            'from'=>$from,
            'to'=>$to,
            'status'=>$evaluation['status'],
            'criteria'=>$evaluation['criteria'],
            'policy'=>$policy,
            'policy_version'=>(string)$policy['policy_version'],
            'actual'=>$actual,
            'approver'=>$approver,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ];
        $this->repository->savePromotionDecision($organizationId,$record);
        $this->telemetry->metric($organizationId,'promotion_pass_rate',$record['status']==='PASSED'?1.0:0.0,['from'=>$from,'to'=>$to]);
        if($record['status']==='PASSED'&&$to==='VALIDATED')$this->telemetry->metric($organizationId,'strategies_validated');
        $this->events->publish($organizationId,'capital_markets.research.strategy_promotion_requested.v1',$strategyVersionId,[
            'decision_id'=>$record['decision_id'],'from'=>$from,'to'=>$to,'status'=>$record['status'],'policy_version'=>$record['policy_version']
        ]);
        if($record['status']==='PASSED'){
            $this->events->publish($organizationId,'capital_markets.research.strategy_promoted.v1',$strategyVersionId,[
                'decision_id'=>$record['decision_id'],'from'=>$from,'to'=>$to
            ]);
        }
        return $record;
    }

    public function evaluateDemotion(
        string $organizationId,
        string $strategyVersionId,
        array $actual,
        array $policy,
        string $approver,
    ):array{
        if($this->repository->getStrategyVersion($organizationId,$strategyVersionId)===null){
            throw new InvalidArgumentException('Demotion requires an existing strategy version.');
        }
        $policyVersion=trim((string)($policy['policy_version']??''));
        if($policyVersion==='')throw new InvalidArgumentException('Demotion policy_version is required.');
        $evaluation=$this->demotionPolicy->evaluate($actual,$policy);
        $record=[
            'decision_id'=>'demotion-'.bin2hex(random_bytes(12)),
            'strategy_version_id'=>$strategyVersionId,
            'decision_type'=>'DEMOTION',
            'status'=>$evaluation['action']==='NONE'?'NO_ACTION':'TRIGGERED',
            'action'=>$evaluation['action'],
            'reasons'=>$evaluation['reasons'],
            'policy'=>$policy,
            'policy_version'=>$policyVersion,
            'actual'=>$actual,
            'approver'=>$approver,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ];
        $this->repository->savePromotionDecision($organizationId,$record);
        if($record['action']==='REJECT')$this->telemetry->metric($organizationId,'strategies_rejected');
        if($record['action']!=='NONE'){
            $eventType=$record['action']==='REJECT'
                ? 'capital_markets.research.strategy_rejected.v1'
                : 'capital_markets.research.strategy_demoted.v1';
            $this->events->publish($organizationId,$eventType,$strategyVersionId,[
                'decision_id'=>$record['decision_id'],
                'action'=>$record['action'],
                'reasons'=>$record['reasons'],
                'policy_version'=>$policyVersion,
            ]);
        }
        return $record;
    }

    public function workspace(string $organizationId):array
    {
        $hypotheses=$this->repository->listHypotheses($organizationId,500);
        $experiments=$this->repository->listExperiments($organizationId,null,500);
        $datasets=$this->repository->listDatasets($organizationId,500);
        $strategyVersions=$this->repository->listAllStrategyVersions($organizationId,500);
        $knowledge=$this->repository->listKnowledge($organizationId,500);
        $results=$this->repository->listResults($organizationId,500);
        $runs=$this->repository->listBacktestRuns($organizationId,500);
        $oosRuns=$this->repository->listOutOfSampleRuns($organizationId,500);
        $paperRuns=$this->repository->listPaperRuns($organizationId,500);
        $scorecards=$this->repository->listScorecards($organizationId,500);
        $rejections=$this->repository->listRejectedHypotheses($organizationId,500);
        $promotion=$this->repository->listAllPromotionDecisions($organizationId,500);
        $completedRuns=array_values(array_filter($runs,static fn(array $r):bool=>($r['status']??'')==='COMPLETED'));
        $failedRuns=array_values(array_filter($runs,static fn(array $r):bool=>($r['status']??'')==='FAILED'));
        return [
            'hypotheses'=>$hypotheses,
            'experiments'=>$experiments,
            'datasets'=>$datasets,
            'strategy_versions'=>$strategyVersions,
            'knowledge'=>$knowledge,
            'results'=>$results,
            'backtest_runs'=>$runs,
            'oos_runs'=>$oosRuns,
            'paper_runs'=>$paperRuns,
            'scorecards'=>$scorecards,
            'rejections'=>$rejections,
            'promotion_decisions'=>$promotion,
            'metrics'=>[
                'hypothesis_count'=>count($hypotheses),
                'experiment_count'=>count($experiments),
                'dataset_count'=>count($datasets),
                'strategy_version_count'=>count($strategyVersions),
                'knowledge_count'=>count($knowledge),
                'result_count'=>count($results),
                'validated_hypotheses'=>count(array_filter($hypotheses,static fn(array $h):bool=>($h['status']??'')==='VALIDATED')),
                'rejected_hypotheses'=>count($rejections),
                'running_experiments'=>count(array_filter($experiments,static fn(array $e):bool=>($e['status']??'')==='RUNNING')),
                'backtest_count'=>count($runs),
                'oos_count'=>count($oosRuns),
                'paper_count'=>count($paperRuns),
                'paper_completed'=>count(array_filter($paperRuns,static fn(array $r):bool=>($r['status']??'')==='COMPLETED')),
                'oos_completed'=>count(array_filter($oosRuns,static fn(array $r):bool=>($r['status']??'')==='COMPLETED')),
                'oos_failed'=>count(array_filter($oosRuns,static fn(array $r):bool=>($r['status']??'')==='FAILED')),
                'backtest_completed'=>count($completedRuns),
                'backtest_failed'=>count($failedRuns),
                'backtest_failure_rate'=>$runs===[]?0:count($failedRuns)/count($runs),
                'promotion_passed'=>count(array_filter($promotion,static fn(array $p):bool=>($p['status']??'')==='PASSED')),
                'promotion_failed'=>count(array_filter($promotion,static fn(array $p):bool=>($p['status']??'')==='FAILED')),
            ],
        ];
    }

    private function assertPromotionEvidence(string $organizationId,string $strategyVersionId,string $from,string $to):void
    {
        if($from==='RESEARCH'&&$to==='BACKTEST'){
            foreach($this->repository->listExperiments($organizationId,null,500) as $experiment){
                if(($experiment['strategy_version_id']??null)!==$strategyVersionId)continue;
                if(empty($experiment['dataset_id'])||empty($experiment['success_criteria'])||empty($experiment['failure_criteria']))continue;
                $hypothesis=$this->repository->getHypothesis($organizationId,(string)($experiment['hypothesis_id']??''));
                if($hypothesis===null||trim((string)($hypothesis['economic_reason']??''))==='')continue;
                return;
            }
            throw new InvalidArgumentException('RESEARCH -> BACKTEST requires an experiment with frozen dataset, measurable criteria and economic rationale.');
        }
        if($from==='BACKTEST'&&$to==='OOS'){
            foreach($this->repository->listBacktestRuns($organizationId,500) as $run){
                if(($run['strategy_version_id']??null)===$strategyVersionId&&($run['status']??null)==='COMPLETED')return;
            }
            throw new InvalidArgumentException('BACKTEST -> OOS requires a completed backtest run.');
        }
        if($from==='OOS'&&$to==='PAPER'){
            foreach($this->repository->listOutOfSampleRuns($organizationId,500) as $run){
                if(($run['strategy_version_id']??null)===$strategyVersionId&&($run['status']??null)==='COMPLETED'&&!empty($run['result_id']))return;
            }
            throw new InvalidArgumentException('OOS -> PAPER requires a completed OOS run with result.');
        }
        if($from==='PAPER'&&$to==='LIMITED_LIVE'){
            foreach($this->repository->listPaperRuns($organizationId,500) as $run){
                if(($run['strategy_version_id']??null)===$strategyVersionId&&($run['status']??null)==='COMPLETED')return;
            }
            throw new InvalidArgumentException('PAPER -> LIMITED_LIVE requires a completed paper run.');
        }
    }

    private function assertResearchReady(array $record):void
    {
        foreach([
            'description','economic_reason','edge_source','expected_behavior','required_data',
            'success_criteria','failure_criteria','risk_assumptions','capital_assumptions'
        ] as $field){
            if(!array_key_exists($field,$record))throw new InvalidArgumentException($field.' is required before READY_FOR_RESEARCH.');
            $value=$record[$field];
            if(is_string($value)&&trim($value)==='')throw new InvalidArgumentException($field.' is required before READY_FOR_RESEARCH.');
            if(is_array($value)&&$value===[])throw new InvalidArgumentException($field.' is required before READY_FOR_RESEARCH.');
        }
    }

    private function canonicalize(array $value):array
    {
        ksort($value);
        foreach($value as $key=>$item){
            if(!is_array($item))continue;
            $value[$key]=array_is_list($item)
                ? array_map(fn(mixed $v):mixed=>is_array($v)?$this->canonicalize($v):$v,$item)
                : $this->canonicalize($item);
        }
        return $value;
    }
}
