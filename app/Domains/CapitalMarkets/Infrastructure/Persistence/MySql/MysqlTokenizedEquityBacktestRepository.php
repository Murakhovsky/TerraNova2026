<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\TokenizedEquityBacktestRepositoryInterface;
use PDO;

final readonly class MysqlTokenizedEquityBacktestRepository implements TokenizedEquityBacktestRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function saveRun(string $organizationId,string $runId,string $hypothesis,string $status,string $datasetHash,array $payload):void
    {
        $this->connection->prepare(
            'INSERT INTO tn_capital_market_backtest_runs
             (organization_id,run_id,hypothesis,status,dataset_hash,from_at,to_at,snapshot_count,train_count,oos_count,config_json,summary_json,created_at)
             VALUES
             (:org,:run_id,:hypothesis,:status,:dataset_hash,:from_at,:to_at,:snapshot_count,:train_count,:oos_count,:config_json,:summary_json,:created_at)'
        )->execute([
            'org'=>$organizationId,
            'run_id'=>$runId,
            'hypothesis'=>$hypothesis,
            'status'=>$status,
            'dataset_hash'=>$datasetHash,
            'from_at'=>$this->mysqlDate((string)$payload['from']),
            'to_at'=>$this->mysqlDate((string)$payload['to']),
            'snapshot_count'=>(int)$payload['snapshot_count'],
            'train_count'=>(int)$payload['train_count'],
            'oos_count'=>(int)$payload['oos_count'],
            'config_json'=>json_encode($payload['config'],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
            'summary_json'=>json_encode($payload['summary'],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),
            'created_at'=>$this->mysqlDate((string)$payload['created_at']),
        ]);
    }

    public function listRuns(string $organizationId,int $limit=50):array
    {
        $limit=max(1,min(250,$limit));
        $statement=$this->connection->prepare(
            'SELECT run_id,hypothesis,status,dataset_hash,from_at,to_at,snapshot_count,train_count,oos_count,config_json,summary_json,created_at
             FROM tn_capital_market_backtest_runs
             WHERE organization_id=:org
             ORDER BY created_at DESC,id DESC
             LIMIT '.$limit
        );
        $statement->execute(['org'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function(array $row):array{
            return [
                'run_id'=>(string)$row['run_id'],
                'hypothesis'=>(string)$row['hypothesis'],
                'status'=>(string)$row['status'],
                'dataset_hash'=>(string)$row['dataset_hash'],
                'from'=>(string)$row['from_at'],
                'to'=>(string)$row['to_at'],
                'snapshot_count'=>(int)$row['snapshot_count'],
                'train_count'=>(int)$row['train_count'],
                'oos_count'=>(int)$row['oos_count'],
                'config'=>$this->object((string)$row['config_json']),
                'summary'=>$this->object((string)$row['summary_json']),
                'created_at'=>(string)$row['created_at'],
            ];
        },$rows);
    }

    private function mysqlDate(string $value):string
    {
        return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s.u');
    }

    /** @return array<string,mixed> */
    private function object(string $json):array
    {
        $value=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        return is_array($value)&&!array_is_list($value)?$value:[];
    }
}
