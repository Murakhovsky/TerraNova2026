<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use Domains\CapitalMarkets\Domain\Research\StrategyScorecardEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\ResearchDuplicateDetector;
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
    ){}

    public function createHypothesis(string $organizationId,array $record):array
    {
        foreach(['hypothesis_id','code','title','economic_reason','edge_source','status','priority'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if($record['status']==='READY_FOR_RESEARCH' && trim((string)$record['economic_reason'])===''){
            throw new InvalidArgumentException('economic_reason is required before READY_FOR_RESEARCH.');
        }
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
        $record['revision']=max(1,(int)($record['revision']??1));
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveHypothesis($organizationId,$record);
        return $record;
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
        return $record;
    }

    public function createExperiment(string $organizationId,array $record):array
    {
        foreach(['experiment_id','hypothesis_id','dataset_id','strategy_version_id','experiment_type','status','success_criteria','failure_criteria'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        if($this->repository->getDataset($organizationId,(string)$record['dataset_id'])===null){
            throw new InvalidArgumentException('Experiment requires a frozen dataset.');
        }
        $record['parameters']=$record['parameters']??[];
        $record['parameters_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['parameters']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['success_criteria_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['success_criteria']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['failure_criteria_hash']=hash('sha256',json_encode($this->canonicalize((array)$record['failure_criteria']),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->repository->saveExperiment($organizationId,$record);
        return $record;
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
        $evaluation=$this->promotionGate->evaluate($from,$to,$actual,$policy);
        $record=[
            'decision_id'=>'promotion-'.bin2hex(random_bytes(12)),
            'strategy_version_id'=>$strategyVersionId,
            'from'=>$from,
            'to'=>$to,
            'status'=>$evaluation['status'],
            'criteria'=>$evaluation['criteria'],
            'policy'=>$policy,
            'actual'=>$actual,
            'approver'=>$approver,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ];
        $this->repository->savePromotionDecision($organizationId,$record);
        return $record;
    }

    public function workspace(string $organizationId):array
    {
        $hypotheses=$this->repository->listHypotheses($organizationId,500);
        $experiments=$this->repository->listExperiments($organizationId,null,500);
        $knowledge=$this->repository->listKnowledge($organizationId,500);
        $results=$this->repository->listResults($organizationId,500);
        $runs=$this->repository->listBacktestRuns($organizationId,500);
        $oosRuns=$this->repository->listOutOfSampleRuns($organizationId,500);
        $scorecards=$this->repository->listScorecards($organizationId,500);
        $rejections=$this->repository->listRejectedHypotheses($organizationId,500);
        $promotion=$this->repository->listAllPromotionDecisions($organizationId,500);
        $completedRuns=array_values(array_filter($runs,static fn(array $r):bool=>($r['status']??'')==='COMPLETED'));
        $failedRuns=array_values(array_filter($runs,static fn(array $r):bool=>($r['status']??'')==='FAILED'));
        return [
            'hypotheses'=>$hypotheses,
            'experiments'=>$experiments,
            'knowledge'=>$knowledge,
            'results'=>$results,
            'backtest_runs'=>$runs,
            'oos_runs'=>$oosRuns,
            'scorecards'=>$scorecards,
            'rejections'=>$rejections,
            'promotion_decisions'=>$promotion,
            'metrics'=>[
                'hypothesis_count'=>count($hypotheses),
                'experiment_count'=>count($experiments),
                'knowledge_count'=>count($knowledge),
                'result_count'=>count($results),
                'validated_hypotheses'=>count(array_filter($hypotheses,static fn(array $h):bool=>($h['status']??'')==='VALIDATED')),
                'rejected_hypotheses'=>count($rejections),
                'running_experiments'=>count(array_filter($experiments,static fn(array $e):bool=>($e['status']??'')==='RUNNING')),
                'backtest_count'=>count($runs),
                'oos_count'=>count($oosRuns),
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
