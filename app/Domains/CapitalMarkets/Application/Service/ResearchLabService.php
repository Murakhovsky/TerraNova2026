<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Domain\Research\StrategyPromotionGate;
use InvalidArgumentException;

final readonly class ResearchLabService
{
    public function __construct(
        private ResearchLabRepositoryInterface $repository,
        private StrategyPromotionGate $promotionGate,
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
        return [
            'hypotheses'=>$this->repository->listHypotheses($organizationId,500),
            'experiments'=>$this->repository->listExperiments($organizationId,null,500),
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
