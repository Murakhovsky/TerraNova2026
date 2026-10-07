<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use InvalidArgumentException;
use RuntimeException;
use Kernel\Queue\Contract\JobQueueInterface;
use Domains\CapitalMarkets\Automation\Job\ResearchBacktestJobHandler;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Research\WalkForwardEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchExecutionBudgetPolicy;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;
use Domains\CapitalMarkets\Domain\Research\ResearchMetricsEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchConfidenceEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;

final readonly class ResearchBacktestService
{
    /** @param iterable<ResearchReplayAdapterInterface> $adapters */
    public function __construct(
        private iterable $adapters,
        private ResearchLabRepositoryInterface $repository,
        private ResearchLabService $lab,
        private WalkForwardEngine $walkForward,
        private ResearchExecutionBudgetPolicy $budget,
        private ResearchIsolationPolicy $isolation,
        private ResearchMetricsEngine $metrics,
        private ResearchConfidenceEngine $confidence,
        private ResearchTelemetry $telemetry,
        private JobQueueInterface $queue,
        private ResearchEventPublisher $events,
    ){}

    public function queue(string $organizationId,array $specification):array
    {
        foreach(['run_id','experiment_id','dataset_id','strategy_version_id','hypothesis_code','partition_name','configuration'] as $required){
            if(!array_key_exists($required,$specification))throw new InvalidArgumentException('Missing '.$required);
        }
        $this->budget->assertBacktest((array)$specification['configuration']);
        $partition=strtoupper(trim((string)$specification['partition_name']));
        if(!in_array($partition,['TRAIN','VALIDATION','OUT_OF_SAMPLE'],true)){
            throw new InvalidArgumentException('Invalid research data partition.');
        }
        $experiment=$this->repository->getExperiment($organizationId,(string)$specification['experiment_id']);
        if($experiment===null)throw new InvalidArgumentException('Backtest requires an existing experiment.');
        if(($experiment['dataset_id']??null)!==$specification['dataset_id']){
            throw new InvalidArgumentException('Backtest dataset must equal frozen experiment dataset.');
        }
        if(($experiment['strategy_version_id']??null)!==$specification['strategy_version_id']){
            throw new InvalidArgumentException('Backtest strategy version must equal experiment strategy version.');
        }
        $specification['reproducibility_fingerprint']=$this->fingerprint($organizationId,$experiment,$specification,$partition);

        $existing=$this->repository->getBacktestRun($organizationId,(string)$specification['run_id']);
        if($existing!==null){
            $status=strtoupper((string)($existing['status']??''));
            if(in_array($status,['QUEUED','RUNNING'],true))return $existing;
            throw new InvalidArgumentException('Terminal backtest run_id cannot be reused; create a new run.');
        }
        $record=$specification;
        $record['partition_name']=$partition;
        $record['status']='QUEUED';
        $record['budget']=$this->budget->estimate((array)$specification['configuration']);
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->lab->recordBacktestRun($organizationId,$record);

        $correlation=trim((string)($specification['correlation_id']??''));
        if($correlation==='')$correlation='CM-RESEARCH-BACKTEST-'.strtoupper(bin2hex(random_bytes(6)));
        $jobId=$this->queue->enqueue(
            $organizationId,
            ResearchBacktestJobHandler::TYPE,
            ['specification'=>$specification],
            $correlation,
            'research-backtest:'.(string)$specification['run_id'],
            3,
            300,
        );
        $record['queue_job_id']=$jobId;
        $record['correlation_id']=$correlation;
        $this->lab->recordBacktestRun($organizationId,$record);
        $this->telemetry->metric($organizationId,'backtest_queued_total',1.0,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
        ]);
        return $record;
    }

    public function cancel(string $organizationId,string $runId,string $reason='USER_CANCELLED'):array
    {
        $run=$this->repository->getBacktestRun($organizationId,$runId);
        if($run===null)throw new InvalidArgumentException('Backtest run not found.');
        if(in_array((string)($run['status']??''),['COMPLETED','FAILED','CANCELLED'],true)){
            throw new InvalidArgumentException('Terminal backtest run cannot be cancelled.');
        }
        $run['status']='CANCELLED';
        $run['cancelled_at']=gmdate('Y-m-d H:i:s');
        $run['cancel_reason']=$reason;
        $this->lab->recordBacktestRun($organizationId,$run);
        if(strtoupper((string)($run['partition_name']??''))==='OUT_OF_SAMPLE'){
            $oos=$this->repository->getOutOfSampleRun($organizationId,$runId);
            if($oos!==null&&!in_array((string)($oos['status']??''),['COMPLETED','FAILED','CANCELLED'],true)){
                $oos['status']='CANCELLED';
                $oos['cancelled_at']=$run['cancelled_at'];
                $oos['cancel_reason']=$reason;
                $experiment=$this->repository->getExperiment($organizationId,(string)$run['experiment_id']);
                if($experiment!==null)$this->lab->recordOutOfSampleRun($organizationId,$oos,$experiment);
            }
        }
        return $run;
    }

    public function run(string $organizationId,array $specification):array
    {
        foreach([
            'run_id','experiment_id','dataset_id','strategy_version_id','hypothesis_code',
            'partition_name','configuration'
        ] as $required){
            if(!array_key_exists($required,$specification))throw new InvalidArgumentException('Missing '.$required);
        }
        $this->budget->assertBacktest((array)$specification['configuration']);
        $partition=strtoupper(trim((string)$specification['partition_name']));
        if(!in_array($partition,['TRAIN','VALIDATION','OUT_OF_SAMPLE'],true)){
            throw new InvalidArgumentException('Invalid research data partition.');
        }
        $specification['partition_name']=$partition;
        $existing=$this->repository->getBacktestRun($organizationId,(string)$specification['run_id']);
        if($existing!==null&&in_array((string)($existing['status']??''),['COMPLETED','FAILED','CANCELLED','INVALIDATED'],true)){
            throw new InvalidArgumentException('Terminal backtest run cannot start again.');
        }
        $experiment=$this->repository->getExperiment($organizationId,(string)$specification['experiment_id']);
        if($experiment===null)throw new InvalidArgumentException('Backtest requires an existing experiment.');
        if(($experiment['dataset_id']??null)!==$specification['dataset_id']){
            throw new InvalidArgumentException('Backtest dataset must equal frozen experiment dataset.');
        }
        if(($experiment['strategy_version_id']??null)!==$specification['strategy_version_id']){
            throw new InvalidArgumentException('Backtest strategy version must equal experiment strategy version.');
        }
        $specification['reproducibility_fingerprint']=$this->fingerprint($organizationId,$experiment,$specification,$partition);

        $oos=null;
        if($partition==='OUT_OF_SAMPLE'){
            $configuration=(array)$specification['configuration'];
            $from=trim((string)($configuration['from']??''));
            $to=trim((string)($configuration['to']??''));
            if($from===''||$to==='')throw new InvalidArgumentException('OUT_OF_SAMPLE run requires configuration.from and configuration.to.');

            $candidate=[
                'run_id'=>$specification['run_id'],
                'experiment_id'=>$specification['experiment_id'],
                'dataset_id'=>$specification['dataset_id'],
                'strategy_version_id'=>$specification['strategy_version_id'],
                'parameters_hash'=>$experiment['parameters_hash']??null,
                'success_criteria_hash'=>$experiment['success_criteria_hash']??null,
                'failure_criteria_hash'=>$experiment['failure_criteria_hash']??null,
                'from'=>$from,'to'=>$to,
                'status'=>'RUNNING',
                'started_at'=>gmdate('Y-m-d H:i:s'),
            ];
            $candidateHypothesis=(string)($experiment['hypothesis_id']??'');
            foreach($this->repository->listOutOfSampleRuns($organizationId,500) as $previous){
                if(($previous['run_id']??null)===$candidate['run_id'])continue;
                if(!in_array((string)($previous['status']??''),['FAILED','COMPLETED'],true))continue;
                $previousExperiment=$this->repository->getExperiment($organizationId,(string)($previous['experiment_id']??''));
                if($previousExperiment===null||(string)($previousExperiment['hypothesis_id']??'')!==$candidateHypothesis)continue;
                $this->isolation->assertNewOosPeriod($previous,$candidate);
                if(
                    ($previous['status']??null)==='FAILED'
                    &&($previous['strategy_version_id']??null)===$candidate['strategy_version_id']
                ){
                    throw new InvalidArgumentException('Failed OOS requires a new Strategy Version before another OOS run.');
                }
            }
            $this->lab->recordOutOfSampleRun($organizationId,$candidate,$experiment);
            $oos=$candidate;
        }

        $adapter=$this->adapter((string)$specification['hypothesis_code']);
        $startedClock=microtime(true);
        $startedAt=gmdate('Y-m-d H:i:s');
        $this->telemetry->metric($organizationId,'backtest_started_total',1.0,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
        ]);
        $record=$specification;
        $record['status']='RUNNING';
        $record['started_at']=$startedAt;
        $this->lab->recordBacktestRun($organizationId,$record);

        try{
            $replay=$adapter->replay(
                $organizationId,
                (string)$specification['hypothesis_code'],
                (array)$specification['configuration']
            );
        }catch(\Throwable $error){
            $this->telemetry->failure($organizationId,'backtest_failed',[
                'run_id'=>(string)$specification['run_id'],
                'hypothesis'=>(string)$specification['hypothesis_code'],
                'partition'=>$partition,
                'error'=>$error->getMessage(),
            ]);
            $record['status']='FAILED';
            $record['completed_at']=gmdate('Y-m-d H:i:s');
            $record['error']=$error->getMessage();
            $this->lab->recordBacktestRun($organizationId,$record);
            if($oos!==null){
                $oos['status']='FAILED';
                $oos['completed_at']=gmdate('Y-m-d H:i:s');
                $oos['error']=$error->getMessage();
                $this->lab->recordOutOfSampleRun($organizationId,$oos,$experiment);
            }
            throw $error;
        }

        $resultId='result-'.substr(hash('sha256',(string)$specification['run_id'].'|'.json_encode($replay,JSON_THROW_ON_ERROR)),0,32);
        $metrics=$this->metrics->calculate((array)($replay['rows']??[]));
        $sampleCount=(int)($replay['sample_count']??$metrics['financial']['sample_count']??0);
        $minimumSample=max(1,(int)($specification['minimum_sample']??100));
        $skipped=max(0,(int)($replay['skipped_count']??0));
        $observed=max(1,$sampleCount+$skipped);
        $dataQuality=max(0,min(100,intdiv($sampleCount*100,$observed)));
        $validatedRate=Decimal::fromString((string)($replay['validated_rate']??'0'));
        $validatedPercent=DecimalMath::multiplyInteger($validatedRate,100);
        $confidence=$this->confidence->calculate(
            $sampleCount,
            $minimumSample,
            $dataQuality,
            max(0,min(100,(int)$validatedPercent->value())),
            $this->regimeDiversityScore((array)($replay['rows']??[])),
            $this->fidelityScore((string)($replay['execution_fidelity']??'LIMITED')),
            (int)($specification['result_stability_score']??50),
        );
        $result=[
            'result_id'=>$resultId,
            'experiment_id'=>$specification['experiment_id'],
            'status'=>$this->resultStatus($replay),
            'metrics'=>[
                'sample_count'=>$replay['sample_count']??0,
                'positive_rate'=>$replay['positive_rate']??0,
                'validated_rate'=>$replay['validated_rate']??0,
            ],
            'financial_metrics'=>array_replace($metrics['financial'],[
                'expected_pnl_total'=>$replay['expected_pnl_total']??'0',
                'expected_pnl_average'=>$replay['expected_pnl_average']??'0',
            ]),
            'risk_metrics'=>$metrics['risk'],
            'execution_metrics'=>[
                'execution_fidelity'=>$replay['execution_fidelity']??'LIMITED',
                'production_economics_reused'=>$replay['production_economics_reused']??false,
            ],
            'statistical_metrics'=>$metrics['statistical'],
            'data_quality'=>[
                'skipped_count'=>$replay['skipped_count']??0,
                'sample_count'=>$replay['sample_count']??0,
            ],
            'limitations'=>($replay['skipped_count']??0)>0?['SOME_SNAPSHOTS_SKIPPED_OR_UNUSABLE']:[],
            'conclusion'=>$this->resultStatus($replay),
            'sample'=>['count'=>$replay['sample_count']??0],
            'execution_fidelity'=>$replay['execution_fidelity']??'LIMITED',
            'confidence'=>$confidence,
        ];
        $this->lab->recordResult($organizationId,$result);

        $completed=$specification;
        $completed['status']='COMPLETED';
        $completed['started_at']=$startedAt;
        $completed['completed_at']=gmdate('Y-m-d H:i:s');
        $completed['result_id']=$resultId;
        $this->lab->recordBacktestRun($organizationId,$completed);
        $duration=max(0.0,microtime(true)-$startedClock);
        $this->telemetry->metric($organizationId,'backtest_duration_seconds',$duration,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
        ]);
        $this->telemetry->metric($organizationId,'backtest_sample_count',(float)$sampleCount,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
        ]);
        $this->telemetry->metric($organizationId,'backtest_completed_total',1.0,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
            'result_status'=>(string)$result['status'],
        ]);
        $this->telemetry->event($organizationId,'backtest_completed',[
            'run_id'=>(string)$specification['run_id'],
            'result_id'=>$resultId,
            'hypothesis'=>(string)$specification['hypothesis_code'],
            'partition'=>$partition,
            'sample_count'=>$sampleCount,
            'confidence'=>$confidence,
            'duration_seconds'=>$duration,
        ]);

        $this->events->publish($organizationId,'capital_markets.research.backtest_completed.v1',(string)$specification['run_id'],[
            'experiment_id'=>$specification['experiment_id'],
            'strategy_version_id'=>$specification['strategy_version_id'],
            'partition'=>$partition,
            'result_id'=>$resultId,
            'result_status'=>$result['status'],
        ]);
        if($oos!==null){
            $oos['status']='COMPLETED';
            $oos['completed_at']=$completed['completed_at'];
            $oos['result_id']=$resultId;
            $this->lab->recordOutOfSampleRun($organizationId,$oos,$experiment);
            $this->events->publish($organizationId,'capital_markets.research.oos_completed.v1',(string)$specification['run_id'],[
                'experiment_id'=>$specification['experiment_id'],
                'strategy_version_id'=>$specification['strategy_version_id'],
                'result_id'=>$resultId,
                'from'=>$oos['from'],'to'=>$oos['to'],
            ]);
        }

        return ['run'=>$completed,'oos_run'=>$oos,'result'=>$result,'replay'=>$replay];
    }

    public function walkForward(string $organizationId,array $specification):array
    {
        foreach(['hypothesis_code','configuration','from','to','train_days','test_days','step_days'] as $required){
            if(!array_key_exists($required,$specification))throw new InvalidArgumentException('Missing '.$required);
        }
        $adapter=$this->adapter((string)$specification['hypothesis_code']);
        $windows=$this->walkForward->windows(
            new DateTimeImmutable((string)$specification['from']),
            new DateTimeImmutable((string)$specification['to']),
            (int)$specification['train_days'],
            (int)$specification['test_days'],
            (int)$specification['step_days'],
        );
        $this->budget->assertWalkForward($specification,count($windows));
        $startedClock=microtime(true);
        $this->telemetry->metric($organizationId,'walk_forward_started_total',1.0,[
            'hypothesis'=>(string)$specification['hypothesis_code'],
        ]);

        $results=[];
        foreach($windows as $index=>$window){
            $configuration=(array)$specification['configuration'];
            $configuration['from']=$window['test']['from'];
            $configuration['to']=$window['test']['to'];
            $replay=$adapter->replay($organizationId,(string)$specification['hypothesis_code'],$configuration);
            $results[]=[
                'window'=>$index+1,
                'train'=>$window['train'],
                'test'=>$window['test'],
                'score'=>(string)($replay['expected_pnl_average']??'0'),
                'sample_count'=>(int)($replay['sample_count']??0),
                'validated_rate'=>(string)($replay['validated_rate']??'0'),
                'replay'=>$replay,
            ];
        }

        $summary=$this->walkForward->summarize($results);
        $this->telemetry->metric($organizationId,'walk_forward_windows',(float)count($results),[
            'hypothesis'=>(string)$specification['hypothesis_code'],
        ]);
        $this->telemetry->metric($organizationId,'walk_forward_duration_seconds',max(0.0,microtime(true)-$startedClock),[
            'hypothesis'=>(string)$specification['hypothesis_code'],
        ]);
        return [
            'hypothesis'=>strtoupper((string)$specification['hypothesis_code']),
            'windows'=>$results,
            'summary'=>$summary,
        ];
    }

    private function fingerprint(string $organizationId,array $experiment,array $specification,string $partition):string
    {
        $dataset=$this->repository->getDataset($organizationId,(string)$specification['dataset_id']);
        if($dataset===null)throw new InvalidArgumentException('Backtest requires an existing frozen dataset.');
        $configuration=(array)$specification['configuration'];
        $payload=[
            'dataset_id'=>(string)$specification['dataset_id'],
            'dataset_snapshot_hash'=>(string)($dataset['snapshot_hash']??''),
            'strategy_version_id'=>(string)$specification['strategy_version_id'],
            'experiment_id'=>(string)$specification['experiment_id'],
            'partition'=>$partition,
            'execution_model_version'=>(string)($experiment['execution_model_version']??'current-paper'),
            'risk_configuration_version'=>(string)($experiment['risk_configuration_version']??'current'),
            'parameters_hash'=>(string)($experiment['parameters_hash']??''),
            'success_criteria_hash'=>(string)($experiment['success_criteria_hash']??''),
            'failure_criteria_hash'=>(string)($experiment['failure_criteria_hash']??''),
            'random_seed'=>(int)($specification['random_seed']??0),
            'application_build'=>(string)($specification['application_build']??'unspecified'),
            'commit_reference'=>(string)($specification['commit_reference']??'unspecified'),
            'configuration'=>$this->canonicalize($configuration),
        ];
        return hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array $value):array
    {
        ksort($value);
        foreach($value as $key=>$item){
            if(is_array($item))$value[$key]=$this->canonicalize($item);
        }
        return $value;
    }

    private function adapter(string $hypothesis):ResearchReplayAdapterInterface
    {
        foreach($this->adapters as $adapter){
            if($adapter->supports($hypothesis))return $adapter;
        }
        throw new RuntimeException('No ResearchReplayAdapter supports '.$hypothesis.'.');
    }

    private function regimeDiversityScore(array $rows):int
    {
        $regimes=[];
        foreach($rows as $row){
            $regime=strtoupper(trim((string)($row['evidence']['market_regime']??'')));
            if($regime!=='')$regimes[$regime]=true;
        }
        return match(count($regimes)){
            0=>0,
            1=>35,
            2=>60,
            3=>80,
            default=>100,
        };
    }

    private function fidelityScore(string $fidelity):int
    {
        return match(strtoupper($fidelity)){
            'HIGH'=>100,
            'MEDIUM'=>80,
            'LIMITED'=>55,
            'SYNTHETIC'=>25,
            default=>0,
        };
    }

    private function resultStatus(array $replay):string
    {
        $sample=(int)($replay['sample_count']??0);
        if($sample<1)return 'INCOMPLETE';
        $rate=Decimal::fromString((string)($replay['validated_rate']??'0'));
        if($rate->compareTo(Decimal::fromString('0.6'))>=0)return 'POSITIVE';
        if($rate->compareTo(Decimal::fromString('0.1'))<=0)return 'NEGATIVE';
        return 'MIXED';
    }
}
