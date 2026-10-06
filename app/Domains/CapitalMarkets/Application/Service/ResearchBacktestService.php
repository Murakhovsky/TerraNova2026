<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchReplayAdapterInterface;
use InvalidArgumentException;
use RuntimeException;

final readonly class ResearchBacktestService
{
    /** @param iterable<ResearchReplayAdapterInterface> $adapters */
    public function __construct(
        private iterable $adapters,
        private ResearchLabRepositoryInterface $repository,
        private ResearchLabService $lab,
    ){}

    public function run(string $organizationId,array $specification):array
    {
        foreach([
            'run_id','experiment_id','dataset_id','strategy_version_id','hypothesis_code',
            'partition_name','configuration','reproducibility_fingerprint'
        ] as $required){
            if(!array_key_exists($required,$specification))throw new InvalidArgumentException('Missing '.$required);
        }
        $experiment=$this->repository->getExperiment($organizationId,(string)$specification['experiment_id']);
        if($experiment===null)throw new InvalidArgumentException('Backtest requires an existing experiment.');
        if(($experiment['dataset_id']??null)!==$specification['dataset_id']){
            throw new InvalidArgumentException('Backtest dataset must equal frozen experiment dataset.');
        }
        if(($experiment['strategy_version_id']??null)!==$specification['strategy_version_id']){
            throw new InvalidArgumentException('Backtest strategy version must equal experiment strategy version.');
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
            $record['run_id']=$specification['run_id'].'-failed';
            $this->lab->recordBacktestRun($organizationId,$record);
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
        $completed['run_id']=$specification['run_id'].'-completed';
        $completed['status']='COMPLETED';
        $completed['started_at']=$startedAt;
        $completed['completed_at']=gmdate('Y-m-d H:i:s');
        $completed['result_id']=$resultId;
        $this->lab->recordBacktestRun($organizationId,$completed);

        return ['run'=>$completed,'result'=>$result,'replay'=>$replay];
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
