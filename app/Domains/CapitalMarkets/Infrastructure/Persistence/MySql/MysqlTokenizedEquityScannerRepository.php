<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityScannerRepositoryInterface;
use PDO;

final readonly class MysqlTokenizedEquityScannerRepository implements TokenizedEquityScannerRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function saveTarget(
        string $organizationId,string $targetId,string $hypothesis,bool $enabled,int $priority,array $config
    ):void{
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_scan_targets
             (organization_id,target_id,hypothesis,enabled,priority,config_json)
             VALUES (:org,:id,:hypothesis,:enabled,:priority,:config)
             ON DUPLICATE KEY UPDATE hypothesis=VALUES(hypothesis),enabled=VALUES(enabled),
             priority=VALUES(priority),config_json=VALUES(config_json),updated_at=CURRENT_TIMESTAMP(6)'
        )->execute([
            'org'=>$organizationId,'id'=>$targetId,'hypothesis'=>$hypothesis,
            'enabled'=>$enabled?1:0,'priority'=>$priority,'config'=>$this->json($config),
        ]);
    }

    public function listTargets(string $organizationId,bool $enabledOnly=false,int $limit=500):array
    {
        $limit=max(1,min(2000,$limit));
        $sql='SELECT target_id,hypothesis,enabled,priority,config_json,created_at,updated_at
              FROM tn_capital_market_scan_targets WHERE organization_id=:org';
        if($enabledOnly)$sql.=' AND enabled=1';
        $sql.=' ORDER BY priority ASC,target_id ASC LIMIT '.$limit;
        $statement=$this->connection->prepare($sql);
        $statement->execute(['org'=>$organizationId]);
        return array_map(function(array $row):array{
            return [
                'target_id'=>(string)$row['target_id'],
                'hypothesis'=>(string)$row['hypothesis'],
                'enabled'=>(bool)$row['enabled'],
                'priority'=>(int)$row['priority'],
                'config'=>$this->object((string)$row['config_json']),
                'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)$row['updated_at'],
            ];
        },$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function schedulerOrganizations(int $limit=500):array
    {
        $limit=max(1,min(5000,$limit));
        $statement=$this->connection->query(
            'SELECT organization_id FROM tn_capital_market_scan_targets
             WHERE enabled=1 GROUP BY organization_id ORDER BY organization_id ASC LIMIT '.$limit
        );
        return array_map(static fn(array $row):string=>(string)$row['organization_id'],$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getRunByIdempotencyKey(string $organizationId,string $idempotencyKey):?array
    {
        $statement=$this->connection->prepare(
            'SELECT run_id,idempotency_key,trigger,status,target_count,completed_count,failed_count,
                    result_json,started_at,completed_at
             FROM tn_capital_market_scan_runs
             WHERE organization_id=:org AND idempotency_key=:key LIMIT 1'
        );
        $statement->execute(['org'=>$organizationId,'key'=>$idempotencyKey]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->run($row):null;
    }

    public function claimRun(
        string $organizationId,string $runId,string $idempotencyKey,string $trigger,string $startedAt
    ):bool{
        try{
            $this->connection->prepare(
                'INSERT INTO tn_capital_market_scan_runs
                 (organization_id,run_id,idempotency_key,trigger,status,target_count,completed_count,failed_count,
                  result_json,started_at,completed_at)
                 VALUES (:org,:run,:key,:trigger,\'RUNNING\',0,0,0,JSON_OBJECT(),:started,NULL)'
            )->execute([
                'org'=>$organizationId,'run'=>$runId,'key'=>$idempotencyKey,'trigger'=>$trigger,
                'started'=>$this->mysqlDate($startedAt),
            ]);
            return true;
        }catch(\PDOException $error){
            if((string)$error->getCode()==='23000')return false;
            throw $error;
        }
    }

    public function saveRun(
        string $organizationId,string $runId,string $idempotencyKey,string $status,
        int $targetCount,int $completedCount,int $failedCount,array $result,string $completedAt
    ):void{
        $this->connection->prepare(
            'UPDATE tn_capital_market_scan_runs
             SET status=:status,target_count=:targets,completed_count=:completed,failed_count=:failed,
                 result_json=:result,completed_at=:finished
             WHERE organization_id=:org AND run_id=:run AND idempotency_key=:key'
        )->execute([
            'org'=>$organizationId,'run'=>$runId,'key'=>$idempotencyKey,'status'=>$status,
            'targets'=>$targetCount,'completed'=>$completedCount,'failed'=>$failedCount,'result'=>$this->json($result),
            'finished'=>$this->mysqlDate($completedAt),
        ]);
    }

    public function listRuns(string $organizationId,int $limit=100):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT run_id,idempotency_key,trigger,status,target_count,completed_count,failed_count,
                    result_json,started_at,completed_at
             FROM tn_capital_market_scan_runs WHERE organization_id=:org
             ORDER BY started_at DESC,id DESC LIMIT '.$limit
        );
        $statement->execute(['org'=>$organizationId]);
        return array_map(fn(array $row):array=>$this->run($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function run(array $row):array
    {
        return [
            'run_id'=>(string)$row['run_id'],'idempotency_key'=>(string)$row['idempotency_key'],
            'trigger'=>(string)$row['trigger'],'status'=>(string)$row['status'],
            'target_count'=>(int)$row['target_count'],'completed_count'=>(int)$row['completed_count'],
            'failed_count'=>(int)$row['failed_count'],'result'=>$this->object((string)$row['result_json']),
            'started_at'=>(string)$row['started_at'],'completed_at'=>(string)$row['completed_at'],
        ];
    }

    private function json(array $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);}
    /** @return array<string,mixed> */
    private function object(string $json):array{
        $value=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        return is_array($value)&&!array_is_list($value)?$value:[];
    }
    private function mysqlDate(string $value):string{return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s.u');}
}
