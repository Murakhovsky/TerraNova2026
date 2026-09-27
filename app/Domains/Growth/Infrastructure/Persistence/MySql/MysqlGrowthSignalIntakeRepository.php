<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthSignalIntakeRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthSignalIntakeRepository implements GrowthSignalIntakeRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createRun(
        string $organizationId,string $runId,string $collectorName,?string $requestCursor,int $requestedLimit,int $actorId
    ): void {
        $this->execute(
            'INSERT INTO tn_growth_signal_collector_runs
             (organization_id,run_id,collector_name,status,request_cursor,requested_limit,started_at,created_by)
             VALUES(:organization_id,:run_id,:collector_name,\'running\',:request_cursor,:requested_limit,NOW(6),:created_by)',
            [
                'organization_id'=>$organizationId,'run_id'=>$runId,'collector_name'=>$collectorName,
                'request_cursor'=>$requestCursor,'requested_limit'=>$requestedLimit,'created_by'=>$actorId,
            ],
        );
    }

    public function viewRun(string $organizationId,string $runId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,collector_name,status,request_cursor,requested_limit,next_cursor,
                    collected_count,accepted_count,duplicate_count,failed_count,error_summary,
                    started_at,finished_at,created_by,created_at
             FROM tn_growth_signal_collector_runs
             WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        foreach(['requested_limit','collected_count','accepted_count','duplicate_count','failed_count'] as $field)$row[$field]=(int)$row[$field];
        return $row;
    }

    public function completeRun(
        string $organizationId,string $runId,string $status,int $collectedCount,int $acceptedCount,
        int $duplicateCount,int $failedCount,?string $nextCursor,?string $errorSummary
    ): void {
        if(!in_array($status,['completed','partial'],true))throw new InvalidArgumentException('Growth collector completion status is invalid.');
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_signal_collector_runs
             SET status=:status,collected_count=:collected_count,accepted_count=:accepted_count,
                 duplicate_count=:duplicate_count,failed_count=:failed_count,next_cursor=:next_cursor,
                 error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'status'=>$status,'collected_count'=>$collectedCount,'accepted_count'=>$acceptedCount,
            'duplicate_count'=>$duplicateCount,'failed_count'=>$failedCount,'next_cursor'=>$nextCursor,
            'error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth collector run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary): void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_signal_collector_runs
             SET status=\'failed\',error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute(['error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth collector run could not be failed.');
    }

    public function claimSource(
        string $organizationId,string $collectorName,string $externalKey,string $payloadFingerprint,string $signalId
    ): bool {
        $externalKeyHash=hash('sha256',$externalKey);
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_signal_source_receipts
             (organization_id,collector_name,external_key_hash,external_key,payload_fingerprint,signal_id)
             VALUES(:organization_id,:collector_name,:external_key_hash,:external_key,:payload_fingerprint,:signal_id)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'collector_name'=>$collectorName,'external_key_hash'=>$externalKeyHash,
            'external_key'=>$externalKey,'payload_fingerprint'=>$payloadFingerprint,'signal_id'=>$signalId,
        ]);
        if($statement->rowCount()===1)return true;

        $row=$this->one(
            'SELECT external_key,payload_fingerprint,signal_id
             FROM tn_growth_signal_source_receipts
             WHERE organization_id=:organization_id AND collector_name=:collector_name
               AND external_key_hash=:external_key_hash LIMIT 1',
            ['organization_id'=>$organizationId,'collector_name'=>$collectorName,'external_key_hash'=>$externalKeyHash],
        );
        if($row===null)throw new InvalidArgumentException('Growth source receipt could not be resolved.');
        if(!hash_equals((string)$row['external_key'],$externalKey)){
            throw new InvalidArgumentException('Growth source external-key hash collision was detected.');
        }
        if(!hash_equals((string)$row['payload_fingerprint'],$payloadFingerprint)||(string)$row['signal_id']!==$signalId){
            throw new InvalidArgumentException('Growth collector external key was reused with a different source payload.');
        }
        return false;
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }
}
