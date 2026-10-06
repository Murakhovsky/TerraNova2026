<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use Domains\CapitalMarkets\Application\Service\ResearchBacktestService;
use Domains\CapitalMarkets\Application\Service\ResearchLabService;
use Domains\CapitalMarkets\Domain\Research\ParameterSensitivityEngine;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\ResearchConfidenceEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchDuplicateDetector;
use Domains\CapitalMarkets\Domain\Research\ResearchExecutionBudgetPolicy;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use Domains\CapitalMarkets\Domain\Research\StrategyScorecardEngine;
use Domains\CapitalMarkets\Domain\Research\WalkForwardEngine;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$repo=new class implements ResearchLabRepositoryInterface{
    public array $hypotheses=[],$datasets=[],$experiments=[],$versions=[],$results=[],$promotions=[],$runs=[],$oos=[],$scorecards=[],$rejections=[],$knowledge=[];

    public function saveHypothesis(string $organizationId,array $record):void{$this->hypotheses[$record['hypothesis_id']]=$record;}
    public function getHypothesis(string $organizationId,string $id):?array{return $this->hypotheses[$id]??null;}
    public function listHypotheses(string $organizationId,int $limit=200):array{return array_slice(array_values($this->hypotheses),0,$limit);}
    public function saveDataset(string $organizationId,array $record):void{$this->datasets[$record['dataset_id']]=$record;}
    public function getDataset(string $organizationId,string $id):?array{return $this->datasets[$id]??null;}
    public function saveExperiment(string $organizationId,array $record):void{$this->experiments[$record['experiment_id']]=$record;}
    public function getExperiment(string $organizationId,string $id):?array{return $this->experiments[$id]??null;}
    public function listExperiments(string $organizationId,?string $hypothesisId=null,int $limit=200):array{
        $rows=array_values($this->experiments);
        if($hypothesisId!==null)$rows=array_values(array_filter($rows,fn(array $r):bool=>($r['hypothesis_id']??null)===$hypothesisId));
        return array_slice($rows,0,$limit);
    }
    public function saveStrategyVersion(string $organizationId,array $record):void{$this->versions[$record['strategy_version_id']]=$record;}
    public function listStrategyVersions(string $organizationId,string $strategyId):array{return array_values(array_filter($this->versions,fn(array $r):bool=>($r['strategy_id']??null)===$strategyId));}
    public function saveResult(string $organizationId,array $record):void{$this->results[$record['experiment_id']]=$record;}
    public function getResultForExperiment(string $organizationId,string $experimentId):?array{return $this->results[$experimentId]??null;}
    public function savePromotionDecision(string $organizationId,array $record):void{$this->promotions[]=$record;}
    public function listPromotionDecisions(string $organizationId,string $strategyVersionId):array{return array_values(array_filter($this->promotions,fn(array $r):bool=>($r['strategy_version_id']??null)===$strategyVersionId));}
    public function listAllPromotionDecisions(string $organizationId,int $limit=200):array{return array_slice($this->promotions,0,$limit);}
    public function saveBacktestRun(string $organizationId,array $record):void{$this->runs[$record['run_id']]=$record;}
    public function listBacktestRuns(string $organizationId,int $limit=200):array{return array_slice(array_values($this->runs),0,$limit);}
    public function getBacktestRun(string $organizationId,string $runId):?array{return $this->runs[$runId]??null;}
    public function saveOutOfSampleRun(string $organizationId,array $record):void{$this->oos[$record['run_id']]=$record;}
    public function getOutOfSampleRun(string $organizationId,string $runId):?array{return $this->oos[$runId]??null;}
    public function listOutOfSampleRuns(string $organizationId,int $limit=200):array{return array_slice(array_values($this->oos),0,$limit);}
    public function saveScorecard(string $organizationId,array $record):void{$this->scorecards[]=$record;}
    public function listScorecards(string $organizationId,int $limit=200):array{return array_slice($this->scorecards,0,$limit);}
    public function saveRejectedHypothesis(string $organizationId,array $record):void{$this->rejections[]=$record;}
    public function listRejectedHypotheses(string $organizationId,int $limit=200):array{return array_slice($this->rejections,0,$limit);}
    public function saveKnowledge(string $organizationId,array $record):void{$this->knowledge[]=$record;}
    public function listKnowledge(string $organizationId,int $limit=200):array{return array_slice($this->knowledge,0,$limit);}
};

$lab=new ResearchLabService(
    $repo,
    new StrategyPromotionGate(),
    new StrategyScorecardEngine(),
    new ResearchIsolationPolicy(),
    new ReplayDataGuard(),
    new ResearchDuplicateDetector(),
);

$adapter=new class implements ResearchReplayAdapterInterface{
    public function supports(string $hypothesisCode):bool{return in_array(strtoupper($hypothesisCode),['H4','H6'],true);}
    public function replay(string $organizationId,string $hypothesisCode,array $configuration):array{
        if(strtoupper($hypothesisCode)==='H4'){
            return [
                'sample_count'=>120,'skipped_count'=>3,'positive_count'=>91,'validated_count'=>88,
                'positive_rate'=>91/120,'validated_rate'=>88/120,
                'expected_pnl_total'=>'780','expected_pnl_average'=>'6.5',
                'execution_fidelity'=>'MEDIUM','production_economics_reused'=>true,'rows'=>[],
            ];
        }
        return [
            'sample_count'=>80,'skipped_count'=>2,'positive_count'=>4,'validated_count'=>2,
            'positive_rate'=>0.05,'validated_rate'=>0.025,
            'expected_pnl_total'=>'-240','expected_pnl_average'=>'-3',
            'execution_fidelity'=>'MEDIUM','production_economics_reused'=>true,'rows'=>[],
        ];
    }
};

$backtests=new ResearchBacktestService(
    [$adapter],$repo,$lab,new WalkForwardEngine(),new ResearchExecutionBudgetPolicy(),new ResearchIsolationPolicy()
);
$org='org-research-acceptance';

$lab->createHypothesis($org,[
    'hypothesis_id'=>'hyp-h4','code'=>'H4','title'=>'Spot/perp basis survives costs',
    'description'=>'Historical basis convergence','economic_reason'=>'Temporary basis dislocation',
    'edge_source'=>'LIQUIDITY','expected_behavior'=>'Positive net convergence after costs',
    'required_data'=>['quotes','funding'],'success_criteria'=>['validated_rate'=>['min'=>0.6]],
    'failure_criteria'=>['validated_rate'=>['max'=>0.1]],'priority'=>'P0','status'=>'READY_FOR_RESEARCH',
]);
$dataset=$lab->freezeDataset($org,[
    'dataset_id'=>'dataset-rv-1','name'=>'RV frozen snapshot set','from'=>'2025-01-01','to'=>'2025-12-31',
    'data_sources'=>['market_snapshots'],'instrument_universe'=>['BTC'],'venue_universe'=>['A','B'],
    'data_types'=>['quotes','funding'],'schema_version'=>'1','quality_status'=>'SUFFICIENT'
]);
$assert(strlen((string)$dataset['snapshot_hash'])===64,'Frozen dataset hash must be SHA-256.');

$lab->createStrategyVersion($org,[
    'strategy_version_id'=>'spot-perp-v2','strategy_id'=>'SpotPerpBasisStrategy','version'=>2,
    'logic_hash'=>hash('sha256','spot-perp-v2'),'status'=>'BACKTEST'
]);
$lab->createExperiment($org,[
    'experiment_id'=>'exp-h4-v2','hypothesis_id'=>'hyp-h4','dataset_id'=>'dataset-rv-1',
    'strategy_version_id'=>'spot-perp-v2','experiment_type'=>'BACKTEST','status'=>'QUEUED',
    'success_criteria'=>['validated_rate'=>['min'=>0.6]],'failure_criteria'=>['validated_rate'=>['max'=>0.1]],
]);

$runSpec=[
    'run_id'=>'run-h4-v2','experiment_id'=>'exp-h4-v2','dataset_id'=>'dataset-rv-1',
    'strategy_version_id'=>'spot-perp-v2','hypothesis_code'=>'H4','partition_name'=>'TRAIN',
    'reproducibility_fingerprint'=>hash('sha256','run-h4-v2'),
    'configuration'=>[
        'fees'=>['spot_rate'=>'0.001','perp_rate'=>'0.001'],
        'slippage'=>['round_trip_bps'=>'2'],
        'snapshot_limit'=>5000,'parameter_combinations'=>1,'maximum_compute_units'=>10000,
    ],
];
$queued=$backtests->queue($org,$runSpec);
$assert($queued['status']==='QUEUED','H4 backtest must enter queue.');
$positive=$backtests->run($org,$runSpec);
$assert($positive['result']['status']==='POSITIVE','H4 positive fixture must produce POSITIVE research result.');
$assert(($repo->runs['run-h4-v2']['status']??null)==='COMPLETED','H4 backtest lifecycle must end COMPLETED.');

$score=$lab->createScorecard($org,'spot-perp-v2',[
    'profitability'=>84,'consistency'=>78,'risk'=>76,'execution_quality'=>80,
    'capital_efficiency'=>72,'capacity'=>70,'robustness'=>81,'data_confidence'=>88,'operational_complexity'=>65,
],[
    'profitability'=>2,'consistency'=>1.5,'risk'=>2,'execution_quality'=>1.5,
    'capital_efficiency'=>1,'capacity'=>1,'robustness'=>1.5,'data_confidence'=>1,'operational_complexity'=>0.5,
],'weights-v1');
$assert($score['composite_score']>=75,'H4 scorecard should clear research quality floor.');

$promotion=$lab->evaluatePromotion($org,'spot-perp-v2','BACKTEST','OOS',[
    'composite_score'=>$score['composite_score'],'sample_count'=>120,'validated_rate'=>88/120,
],[
    'composite_score'=>['min'=>75],'sample_count'=>['min'=>100],'validated_rate'=>['min'=>0.6],
],'human:acceptance');
$assert($promotion['status']==='PASSED','H4 must pass deterministic BACKTEST -> OOS gate.');

$lab->createHypothesis($org,[
    'hypothesis_id'=>'hyp-h6','code'=>'H6','title'=>'Cross venue funding differential persists',
    'description'=>'Funding differential test','economic_reason'=>'Venue-specific funding imbalance',
    'edge_source'=>'FUNDING','expected_behavior'=>'Positive net funding spread after costs',
    'required_data'=>['funding','quotes'],'success_criteria'=>['validated_rate'=>['min'=>0.6]],
    'failure_criteria'=>['validated_rate'=>['max'=>0.1]],'priority'=>'P1','status'=>'READY_FOR_RESEARCH',
]);
$lab->createStrategyVersion($org,[
    'strategy_version_id'=>'cross-funding-v1','strategy_id'=>'CrossVenueFundingStrategy','version'=>1,
    'logic_hash'=>hash('sha256','cross-funding-v1'),'status'=>'BACKTEST'
]);
$lab->createExperiment($org,[
    'experiment_id'=>'exp-h6-v1','hypothesis_id'=>'hyp-h6','dataset_id'=>'dataset-rv-1',
    'strategy_version_id'=>'cross-funding-v1','experiment_type'=>'BACKTEST','status'=>'QUEUED',
    'success_criteria'=>['validated_rate'=>['min'=>0.6]],'failure_criteria'=>['validated_rate'=>['max'=>0.1]],
]);
$negative=$backtests->run($org,[
    'run_id'=>'run-h6-v1','experiment_id'=>'exp-h6-v1','dataset_id'=>'dataset-rv-1',
    'strategy_version_id'=>'cross-funding-v1','hypothesis_code'=>'H6','partition_name'=>'TRAIN',
    'reproducibility_fingerprint'=>hash('sha256','run-h6-v1'),
    'configuration'=>[
        'fees'=>['venue_a_rate'=>'0.001','venue_b_rate'=>'0.001'],
        'slippage'=>['round_trip_bps'=>'2'],
        'snapshot_limit'=>5000,'parameter_combinations'=>1,'maximum_compute_units'=>10000,
    ],
]);
$assert($negative['result']['status']==='NEGATIVE','H6 negative fixture must stay NEGATIVE.');

$lab->rejectHypothesis($org,[
    'rejection_id'=>'reject-h6-v1','hypothesis_id'=>'hyp-h6','reason'=>'COSTS_DESTROY_EDGE',
    'evidence'=>['result_id'=>$negative['result']['result_id'],'expected_pnl_average'=>'-3'],
    'experiment_ids'=>['exp-h6-v1'],
]);
$lab->recordKnowledge($org,[
    'knowledge_id'=>'knowledge-h6-costs','knowledge_type'=>'REJECTED_FINDING',
    'statement'=>'H6 cross-venue funding differential did not survive explicit fees and slippage in the tested sample.',
    'experiment_ids'=>['exp-h6-v1'],'dataset_ids'=>['dataset-rv-1'],
    'strategy_version_ids'=>['cross-funding-v1'],'result_ids'=>[$negative['result']['result_id']],
]);
$assert(count($repo->rejections)===1,'Negative H6 must create a rejected-hypothesis record.');
$assert(count($repo->knowledge)===1,'Negative H6 must become reusable ResearchKnowledge.');

$secondResultBlocked=false;
try{$lab->recordResult($org,$negative['result']);}
catch(InvalidArgumentException){$secondResultBlocked=true;}
$assert($secondResultBlocked,'Completed experiment result must remain immutable.');

echo "Capital Markets Research Lab H4 positive / H6 negative acceptance passed.\n";
