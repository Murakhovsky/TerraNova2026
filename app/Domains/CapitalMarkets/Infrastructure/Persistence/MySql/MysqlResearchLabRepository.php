<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use PDO;
use RuntimeException;

final readonly class MysqlResearchLabRepository implements ResearchLabRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function saveHypothesis(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_research_hypotheses',$organizationId,$record,'hypothesis_id');
    }

    public function getHypothesis(string $organizationId,string $id):?array
    {
        return $this->one('tn_capital_market_research_hypotheses','hypothesis_id',$organizationId,$id);
    }

    public function listHypotheses(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_research_hypotheses',$organizationId,$limit);
    }

    public function saveDataset(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_research_datasets',$organizationId,$record,'dataset_id');
    }

    public function getDataset(string $organizationId,string $id):?array
    {
        return $this->one('tn_capital_market_research_datasets','dataset_id',$organizationId,$id);
    }

    public function saveExperiment(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_research_experiments',$organizationId,$record,'experiment_id');
    }

    public function getExperiment(string $organizationId,string $id):?array
    {
        return $this->one('tn_capital_market_research_experiments','experiment_id',$organizationId,$id);
    }

    public function listExperiments(string $organizationId,?string $hypothesisId=null,int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));
        $sql='SELECT record_json FROM tn_capital_market_research_experiments WHERE organization_id=:organization_id';
        $params=['organization_id'=>$organizationId];
        if($hypothesisId!==null){
            $sql.=' AND hypothesis_id=:hypothesis_id';
            $params['hypothesis_id']=$hypothesisId;
        }
        $sql.=' ORDER BY id DESC LIMIT '.$limit;
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return array_map([$this,'decode'],array_column($statement->fetchAll(PDO::FETCH_ASSOC),'record_json'));
    }

    public function saveStrategyVersion(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_strategy_versions',$organizationId,$record,'strategy_version_id');
    }

    public function listStrategyVersions(string $organizationId,string $strategyId):array
    {
        $statement=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_strategy_versions WHERE organization_id=:organization_id AND strategy_id=:strategy_id ORDER BY version ASC'
        );
        $statement->execute(['organization_id'=>$organizationId,'strategy_id'=>$strategyId]);
        return array_map([$this,'decode'],array_column($statement->fetchAll(PDO::FETCH_ASSOC),'record_json'));
    }

    public function saveResult(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_research_results',$organizationId,$record,'result_id');
    }

    public function getResultForExperiment(string $organizationId,string $experimentId):?array
    {
        return $this->one('tn_capital_market_research_results','experiment_id',$organizationId,$experimentId);
    }

    public function listResults(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_research_results',$organizationId,$limit);
    }

    public function savePromotionDecision(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_strategy_promotion_decisions',$organizationId,$record,'decision_id');
    }

    public function listPromotionDecisions(string $organizationId,string $strategyVersionId):array
    {
        $statement=$this->connection->prepare(
            'SELECT record_json FROM tn_capital_market_strategy_promotion_decisions WHERE organization_id=:organization_id AND strategy_version_id=:strategy_version_id ORDER BY id DESC'
        );
        $statement->execute(['organization_id'=>$organizationId,'strategy_version_id'=>$strategyVersionId]);
        return array_map([$this,'decode'],array_column($statement->fetchAll(PDO::FETCH_ASSOC),'record_json'));
    }


    public function listAllPromotionDecisions(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_strategy_promotion_decisions',$organizationId,$limit);
    }


    public function saveBacktestRun(string $organizationId,array $record):void
    {
        $required=['run_id','experiment_id','dataset_id','strategy_version_id','partition_name','status','reproducibility_fingerprint'];
        foreach($required as $key){
            if(!array_key_exists($key,$record))throw new RuntimeException('Backtest run missing '.$key);
        }
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_backtest_runs
             (organization_id,run_id,experiment_id,dataset_id,strategy_version_id,partition_name,status,reproducibility_fingerprint,record_json,created_at)
             VALUES (:organization_id,:run_id,:experiment_id,:dataset_id,:strategy_version_id,:partition_name,:status,:fingerprint,:record_json,:created_at)
             ON DUPLICATE KEY UPDATE status=VALUES(status),record_json=VALUES(record_json),reproducibility_fingerprint=VALUES(reproducibility_fingerprint)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'run_id'=>(string)$record['run_id'],
            'experiment_id'=>(string)$record['experiment_id'],
            'dataset_id'=>(string)$record['dataset_id'],
            'strategy_version_id'=>(string)$record['strategy_version_id'],
            'partition_name'=>(string)$record['partition_name'],
            'status'=>(string)$record['status'],
            'fingerprint'=>(string)$record['reproducibility_fingerprint'],
            'record_json'=>json_encode($record,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
            'created_at'=>$record['created_at']??gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function listBacktestRuns(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_backtest_runs',$organizationId,$limit);
    }

    public function getBacktestRun(string $organizationId,string $runId):?array
    {
        return $this->one('tn_capital_market_backtest_runs','run_id',$organizationId,$runId);
    }

    public function saveOutOfSampleRun(string $organizationId,array $record):void
    {
        $required=['run_id','experiment_id','dataset_id','strategy_version_id','status'];
        foreach($required as $key){
            if(!array_key_exists($key,$record))throw new RuntimeException('OOS run missing '.$key);
        }
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_oos_runs
             (organization_id,run_id,experiment_id,dataset_id,strategy_version_id,status,record_json,created_at)
             VALUES (:organization_id,:run_id,:experiment_id,:dataset_id,:strategy_version_id,:status,:record_json,:created_at)
             ON DUPLICATE KEY UPDATE status=VALUES(status),record_json=VALUES(record_json)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'run_id'=>(string)$record['run_id'],
            'experiment_id'=>(string)$record['experiment_id'],
            'dataset_id'=>(string)$record['dataset_id'],
            'strategy_version_id'=>(string)$record['strategy_version_id'],
            'status'=>(string)$record['status'],
            'record_json'=>json_encode($record,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
            'created_at'=>$record['created_at']??gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function getOutOfSampleRun(string $organizationId,string $runId):?array
    {
        return $this->one('tn_capital_market_oos_runs','run_id',$organizationId,$runId);
    }

    public function listOutOfSampleRuns(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_oos_runs',$organizationId,$limit);
    }

    public function saveScorecard(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_strategy_scorecards',$organizationId,$record,'scorecard_id');
    }

    public function listScorecards(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_strategy_scorecards',$organizationId,$limit);
    }

    public function saveRejectedHypothesis(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_rejected_hypotheses',$organizationId,$record,'rejection_id');
    }

    public function listRejectedHypotheses(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_rejected_hypotheses',$organizationId,$limit);
    }

    public function saveKnowledge(string $organizationId,array $record):void
    {
        $this->insert('tn_capital_market_research_knowledge',$organizationId,$record,'knowledge_id');
    }

    public function listKnowledge(string $organizationId,int $limit=200):array
    {
        return $this->many('tn_capital_market_research_knowledge',$organizationId,$limit);
    }

    private function insert(string $table,string $organizationId,array $record,string $idKey):void
    {
        $id=trim((string)($record[$idKey]??''));
        if($organizationId===''||$id==='')throw new RuntimeException('Research record identity missing.');

        $columns=['organization_id',$idKey,'record_json','created_at'];
        $params=[
            'organization_id'=>$organizationId,
            $idKey=>$id,
            'record_json'=>json_encode($record,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
            'created_at'=>$record['created_at']??gmdate('Y-m-d H:i:s'),
        ];

        foreach(['hypothesis_id','experiment_id','dataset_id','strategy_id','strategy_version_id','version','status','snapshot_hash','partition_name','reproducibility_fingerprint','composite_score','weight_version','reason','knowledge_type'] as $column){
            if($column===$idKey||!array_key_exists($column,$record))continue;
            $columns[]=$column;
            $params[$column]=$record[$column];
        }

        $quoted=implode(',',array_map(static fn(string $c):string=>'`'.$c.'`',$columns));
        $values=implode(',',array_map(static fn(string $c):string=>':'.$c,$columns));
        $statement=$this->connection->prepare('INSERT INTO '.$table.' ('.$quoted.') VALUES ('.$values.')');
        $statement->execute($params);
    }

    private function one(string $table,string $key,string $organizationId,string $id):?array
    {
        $statement=$this->connection->prepare(
            'SELECT record_json FROM '.$table.' WHERE organization_id=:organization_id AND '.$key.'=:id ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'id'=>$id]);
        $value=$statement->fetchColumn();
        return $value===false?null:$this->decode((string)$value);
    }

    private function many(string $table,string $organizationId,int $limit):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT record_json FROM '.$table.' WHERE organization_id=:organization_id ORDER BY id DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map([$this,'decode'],array_column($statement->fetchAll(PDO::FETCH_ASSOC),'record_json'));
    }

    private function decode(string $json):array
    {
        $decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($decoded))throw new RuntimeException('Invalid research record JSON.');
        return $decoded;
    }
}
