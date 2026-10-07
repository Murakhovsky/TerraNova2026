<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsTradingRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use InvalidArgumentException;

final readonly class ResearchPaperRunService
{
    public function __construct(
        private ResearchLabRepositoryInterface $research,
        private CapitalMarketsTradingRepositoryInterface $trading,
        private ResearchEventPublisher $events,
    ){}

    public function start(string $organizationId,array $record):array
    {
        foreach(['run_id','experiment_id','strategy_version_id','execution_ids'] as $required){
            if(!array_key_exists($required,$record))throw new InvalidArgumentException('Missing '.$required);
        }
        $runId=trim((string)$record['run_id']);
        if($runId==='')throw new InvalidArgumentException('run_id is required.');

        $existing=$this->research->getPaperRun($organizationId,$runId);
        if($existing!==null){
            $status=strtoupper((string)($existing['status']??''));
            if($status==='RUNNING')return $existing;
            throw new InvalidArgumentException('Terminal paper run_id cannot be reused.');
        }

        $experiment=$this->research->getExperiment($organizationId,(string)$record['experiment_id']);
        if($experiment===null)throw new InvalidArgumentException('Paper run requires an existing experiment.');
        if(($experiment['strategy_version_id']??null)!==$record['strategy_version_id']){
            throw new InvalidArgumentException('Paper run strategy version must equal experiment strategy version.');
        }
        if($this->research->getStrategyVersion($organizationId,(string)$record['strategy_version_id'])===null){
            throw new InvalidArgumentException('Paper run requires an existing strategy version.');
        }

        $executionIds=array_values(array_unique(array_map('strval',(array)$record['execution_ids'])));
        if($executionIds===[])throw new InvalidArgumentException('Paper run requires execution_ids.');
        foreach($executionIds as $executionId){
            if(trim($executionId)===''||$this->trading->getExecution($organizationId,$executionId)===null){
                throw new InvalidArgumentException('Unknown paper execution: '.$executionId);
            }
        }

        $record['execution_ids']=$executionIds;
        $record['status']='RUNNING';
        $record['started_at']=$record['started_at']??gmdate('Y-m-d H:i:s');
        $record['created_at']=$record['created_at']??$record['started_at'];
        $record['performance_snapshot']=[];
        $this->research->savePaperRun($organizationId,$record);
        return $record;
    }

    public function complete(string $organizationId,string $runId,array $performance,string $resultId='',string $decisionId=''):array
    {
        $run=$this->research->getPaperRun($organizationId,$runId);
        if($run===null)throw new InvalidArgumentException('Paper run not found.');
        if(($run['status']??null)!=='RUNNING')throw new InvalidArgumentException('Only RUNNING paper run can be completed.');
        if($performance===[])throw new InvalidArgumentException('Paper completion requires performance snapshot.');

        $run['status']='COMPLETED';
        $run['completed_at']=gmdate('Y-m-d H:i:s');
        $run['performance_snapshot']=$performance;
        $run['result_id']=$resultId!==''?$resultId:null;
        $run['decision_id']=$decisionId!==''?$decisionId:null;
        $this->research->savePaperRun($organizationId,$run);
        $this->events->publish(
            $organizationId,
            'capital_markets.research.paper_completed.v1',
            $runId,
            [
                'experiment_id'=>$run['experiment_id'],
                'strategy_version_id'=>$run['strategy_version_id'],
                'execution_ids'=>$run['execution_ids'],
                'result_id'=>$run['result_id'],
                'decision_id'=>$run['decision_id'],
            ]
        );
        return $run;
    }

    public function cancel(string $organizationId,string $runId,string $reason='USER_CANCELLED'):array
    {
        $run=$this->research->getPaperRun($organizationId,$runId);
        if($run===null)throw new InvalidArgumentException('Paper run not found.');
        if(($run['status']??null)!=='RUNNING')throw new InvalidArgumentException('Only RUNNING paper run can be cancelled.');
        $run['status']='CANCELLED';
        $run['completed_at']=gmdate('Y-m-d H:i:s');
        $run['cancel_reason']=$reason;
        $this->research->savePaperRun($organizationId,$run);
        return $run;
    }
}
