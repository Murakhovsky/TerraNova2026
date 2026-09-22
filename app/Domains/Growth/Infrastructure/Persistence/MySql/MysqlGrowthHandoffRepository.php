<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthHandoffRepositoryInterface;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthHandoffRepository implements GrowthHandoffRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createAttempt(
        string $organizationId,string $attemptId,OpportunityHandoff $handoff,string $payloadFingerprint,int $actorId
    ): void {
        $this->execute(
            'INSERT INTO tn_growth_handoff_attempts
             (organization_id,attempt_id,candidate_id,target_domain,status,package_json,payload_fingerprint,started_at,created_by)
             VALUES(:organization_id,:attempt_id,:candidate_id,:target_domain,\'running\',:package_json,:payload_fingerprint,NOW(6),:created_by)',
            [
                'organization_id'=>$organizationId,'attempt_id'=>$attemptId,'candidate_id'=>$handoff->candidateId,
                'target_domain'=>$handoff->targetDomain,'package_json'=>$this->encode($handoff->toArray()),
                'payload_fingerprint'=>$payloadFingerprint,'created_by'=>$actorId,
            ],
        );
    }

    public function viewAttempt(string $organizationId,string $attemptId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,attempt_id,candidate_id,target_domain,status,package_json,payload_fingerprint,
                    target_reference_type,target_reference_id,reason,error_summary,started_at,finished_at,created_by,created_at
             FROM tn_growth_handoff_attempts
             WHERE organization_id=:organization_id AND attempt_id=:attempt_id LIMIT 1',
            ['organization_id'=>$organizationId,'attempt_id'=>$attemptId],
        );
        return $this->hydrateAttempt($row);
    }

    public function hasRunningAttempt(string $organizationId,string $candidateId): bool
    {
        return $this->scalar(
            'SELECT 1 FROM tn_growth_handoff_attempts
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id AND status=\'running\' LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        )!==false;
    }

    public function acceptAttempt(
        string $organizationId,string $attemptId,string $referenceType,string $referenceId,string $reason
    ): void {
        $this->complete(
            $organizationId,$attemptId,'accepted',$reason,null,$referenceType,$referenceId,
        );
    }

    public function rejectAttempt(string $organizationId,string $attemptId,string $reason): void
    {
        $this->complete($organizationId,$attemptId,'rejected',$reason,null,null,null);
    }

    public function failAttempt(string $organizationId,string $attemptId,string $errorSummary): void
    {
        $this->complete($organizationId,$attemptId,'failed',null,$errorSummary,null,null);
    }

    public function latestAttempt(string $organizationId,string $candidateId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,attempt_id,candidate_id,target_domain,status,package_json,payload_fingerprint,
                    target_reference_type,target_reference_id,reason,error_summary,started_at,finished_at,created_by,created_at
             FROM tn_growth_handoff_attempts
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY started_at DESC,attempt_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        return $this->hydrateAttempt($row);
    }

    private function complete(
        string $organizationId,string $attemptId,string $status,?string $reason,?string $errorSummary,
        ?string $referenceType,?string $referenceId
    ): void {
        if(!in_array($status,['accepted','rejected','failed'],true)){
            throw new InvalidArgumentException('Growth handoff completion status is invalid.');
        }
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_handoff_attempts
             SET status=:status,target_reference_type=:target_reference_type,target_reference_id=:target_reference_id,
                 reason=:reason,error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND attempt_id=:attempt_id AND status=\'running\''
        );
        $statement->execute([
            'status'=>$status,'target_reference_type'=>$referenceType,'target_reference_id'=>$referenceId,
            'reason'=>$reason,'error_summary'=>$errorSummary,'organization_id'=>$organizationId,'attempt_id'=>$attemptId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth handoff attempt could not be completed.');
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateAttempt(?array $row): ?array
    {
        if($row===null)return null;
        $package=json_decode((string)$row['package_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($package)||array_is_list($package))throw new InvalidArgumentException('Stored Growth handoff package is invalid.');
        $row['package']=$package;
        unset($row['package_json']);
        return $row;
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

    private function scalar(string $sql,array $params): string|int|false
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }
}
