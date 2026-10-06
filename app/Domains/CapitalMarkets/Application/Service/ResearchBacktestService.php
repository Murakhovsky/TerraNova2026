<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Research\WalkForwardEngine;
use Domains\CapitalMarkets\Domain\Research\ResearchExecutionBudgetPolicy;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;

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
    ){}

    public function queue(string $organizationId,array $specification):array
    {
        foreach(['run_id','experiment_id','dataset_id','strategy_version_id','hypothesis_code','partition_name','configuration','reproducibility_fingerprint'] as $required){
            if(!array_key_exists($required,$specification))throw new InvalidArgumentException('Missing '.$required);
        }
        $this->budget->assertBacktest((array)$specification['configuration']);
        $partition=strtoupper(trim((string)$specification['partition_name']));
        if(!in_array($partition,['TRAIN','VALIDATION','OUT_OF_SAMPLE'],true)){
            throw new InvalidArgumentException('Invalid research data partition.');
        }
        $record=$specification;
        $record['partition_name']=$partition;
        $record['status']='QUEUED';
        $record['budget']=$this->budget->estimate((array)$specification['configuration']);
        $record['created_at']=$record['created_at']??gmdate('Y-m-d H:i:s');
        $this->lab->recordBacktestRun($organizationId,$record);
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
        return $run;
    }

    public function run(string $organizationId,array $specification):array
    {
        foreach([
            'run_id','experiment_id','dataset_id','strategy_version_id','hypothesis_code',
            'partition_name','configuration','reproducibility_fingerprint'
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
        if($existing!==null&&($existing['status']??null)==='CANCELLED'){
            throw new InvalidArgumentException('Cancelled backtest run cannot start.');
        }
        $experiment=$this->repository->getExperiment($organizationId,(string)$specification['experiment_id']);
        if($experiment===null)throw new InvalidArgumentException('Backtest requires an existing experiment.');
        if(($experiment['dataset_id']??null)!==$specification['dataset_id']){
            throw new InvalidArgumentException('Backtest dataset must equal frozen experiment dataset.');
        }
        if(($experiment['strategy_version_id']??null)!==$specification['strategy_version_id']){
            throw new InvalidArgumentException('Backtest strategy version must equal experiment strategy version.');
        }

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
            foreach($this->repository->listOutOfSampleRuns($organizationId,500) as $previous){
                if(($previous['run_id']??null)===$candidate['run_id'])continue;
                if(($previous['strategy_version_id']??null)!==$candidate['strategy_version_id'])continue;
                if(!in_array((string)($previous['status']??''),['FAILED','COMPLETED'],true))continue;
                $this->isolation->assertNewOosPeriod($previous,$candidate);
            }
            $this->lab->recordOutOfSampleRun($organizationId,$candidate,$experiment);
            $oos=$candidate;
        }

        $adapter=$this->adapter((string)$specification['hypothesis_code']);
        $startedAt=gmdate('Y-m-d H:i:s');
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
        $result=[
            'result_id'=>$resultId,
            'experiment_id'=>$specification['experiment_id'],
            'status'=>$this->resultStatus($replay),
            'metrics'=>[
                'sample_count'=>$replay['sample_count']??0,
                'positive_rate'=>$replay['positive_rate']??0,
                'validated_rate'=>$replay['validated_rate']??0,
            ],
            'financial_metrics'=>[
                'expected_pnl_total'=>$replay['expected_pnl_total']??'0',
                'expected_pnl_average'=>$replay['expected_pnl_average']??'0',
            ],
            'risk_metrics'=>[],
            'execution_metrics'=>[
                'execution_fidelity'=>$replay['execution_fidelity']??'LIMITED',
                'production_economics_reused'=>$replay['production_economics_reused']??false,
            ],
            'statistical_metrics'=>[],
            'data_quality'=>[
                'skipped_count'=>$replay['skipped_count']??0,
                'sample_count'=>$replay['sample_count']??0,
            ],
            'limitations'=>($replay['skipped_count']??0)>0?['SOME_SNAPSHOTS_SKIPPED_OR_UNUSABLE']:[],
            'conclusion'=>$this->resultStatus($replay),
            'sample'=>['count'=>$replay['sample_count']??0],
            'execution_fidelity'=>$replay['execution_fidelity']??'LIMITED',
            'confidence'=>0,
        ];
        $this->lab->recordResult($organizationId,$result);

        $completed=$specification;
        $completed['status']='COMPLETED';
        $completed['started_at']=$startedAt;
        $completed['completed_at']=gmdate('Y-m-d H:i:s');
        $completed['result_id']=$resultId;
        $this->lab->recordBacktestRun($organizationId,$completed);
        if($oos!==null){
            $oos['status']='COMPLETED';
            $oos['completed_at']=$completed['completed_at'];
            $oos['result_id']=$resultId;
            $this->lab->recordOutOfSampleRun($organizationId,$oos,$experiment);
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
                'score'=>(float)($replay['expected_pnl_average']??0),
                'sample_count'=>(int)($replay['sample_count']??0),
                'validated_rate'=>(float)($replay['validated_rate']??0),
                'replay'=>$replay,
            ];
        }

        return [
            'hypothesis'=>strtoupper((string)$specification['hypothesis_code']),
            'windows'=>$results,
            'summary'=>$this->walkForward->summarize($results),
        ];
    }

    private function adapter(string $hypothesis):ResearchReplayAdapterInterface
    {
        foreach($this->adapters as $adapter){
            if($adapter->supports($hypothesis))return $adapter;
        }
        throw new RuntimeException('No ResearchReplayAdapter supports '.$hypothesis.'.');
    }

    private function resultStatus(array $replay):string
    {
        $sample=(int)($replay['sample_count']??0);
        if($sample<1)return 'INCOMPLETE';
        $rate=(float)($replay['validated_rate']??0);
        if($rate>=0.6)return 'POSITIVE';
        if($rate<=0.1)return 'NEGATIVE';
        return 'MIXED';
    }
}
