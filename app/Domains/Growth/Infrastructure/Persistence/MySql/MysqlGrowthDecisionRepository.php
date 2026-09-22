<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthDecisionRepositoryInterface;
use Domains\Growth\Domain\QualificationEvaluation;
use Domains\Growth\Domain\QualificationPolicy;
use Domains\Growth\Domain\QualificationPolicyCriteria;
use Domains\Growth\Domain\QualificationPolicyStatus;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthDecisionRepository implements GrowthDecisionRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createPolicy(QualificationPolicy $policy,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_qualification_policies
             (organization_id,policy_id,revision,name,status,criteria_json,created_by,updated_by)
             VALUES(:organization_id,:policy_id,:revision,:name,:status,:criteria_json,:created_by,:updated_by)',
            [
                'organization_id'=>$policy->organizationId->value(),'policy_id'=>$policy->id,'revision'=>$policy->revision,
                'name'=>$policy->name,'status'=>$policy->status()->value,'criteria_json'=>$this->encode($policy->criteria->toArray()),
                'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function lockPolicy(string $organizationId,string $policyId,int $revision): QualificationPolicy
    {
        $row=$this->one(
            'SELECT organization_id,policy_id,revision,name,status,criteria_json
             FROM tn_growth_qualification_policies
             WHERE organization_id=:organization_id AND policy_id=:policy_id AND revision=:revision
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'policy_id'=>$policyId,'revision'=>$revision],
        );
        if($row===null)throw new InvalidArgumentException('Growth qualification policy was not found.');
        return $this->hydratePolicy($row);
    }

    public function updatePolicy(QualificationPolicy $policy,int $actorId): void
    {
        $activated=$policy->status()===QualificationPolicyStatus::Active?'NOW(6)':'activated_at';
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_qualification_policies
             SET status=:status,updated_by=:updated_by,updated_at=NOW(6),activated_at='.$activated.'
             WHERE organization_id=:organization_id AND policy_id=:policy_id AND revision=:revision'
        );
        $statement->execute([
            'status'=>$policy->status()->value,'updated_by'=>$actorId,'organization_id'=>$policy->organizationId->value(),
            'policy_id'=>$policy->id,'revision'=>$policy->revision,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth qualification policy update did not change exactly one row.');
    }

    public function archiveOtherActivePolicyRevisions(string $organizationId,string $policyId,int $exceptRevision,int $actorId): void
    {
        $this->execute(
            'UPDATE tn_growth_qualification_policies
             SET status=\\'archived\\',archived_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND policy_id=:policy_id
               AND revision<>:except_revision AND status=\\'active\\'',
            [
                'updated_by'=>$actorId,'organization_id'=>$organizationId,
                'policy_id'=>$policyId,'except_revision'=>$exceptRevision,
            ],
        );
    }

    public function viewPolicy(string $organizationId,string $policyId,int $revision): ?array
    {
        $row=$this->one(
            'SELECT organization_id,policy_id,revision,name,status,criteria_json,activated_at,archived_at,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_qualification_policies
             WHERE organization_id=:organization_id AND policy_id=:policy_id AND revision=:revision LIMIT 1',
            ['organization_id'=>$organizationId,'policy_id'=>$policyId,'revision'=>$revision],
        );
        if($row===null)return null;
        $row['criteria']=$this->decodeObject((string)$row['criteria_json']);
        unset($row['criteria_json']);
        $row['revision']=(int)$row['revision'];
        return $row;
    }

    public function createEvaluation(string $evaluationId,string $organizationId,QualificationEvaluation $evaluation,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_candidate_evaluations
             (organization_id,evaluation_id,candidate_id,policy_id,policy_revision,outcome,rationale_json,score_json,
              failed_criteria_json,reason,model_version,evaluated_at,created_by)
             VALUES(:organization_id,:evaluation_id,:candidate_id,:policy_id,:policy_revision,:outcome,:rationale_json,:score_json,
                    :failed_criteria_json,:reason,:model_version,:evaluated_at,:created_by)',
            [
                'organization_id'=>$organizationId,'evaluation_id'=>$evaluationId,'candidate_id'=>$evaluation->candidateId,
                'policy_id'=>$evaluation->policyId,'policy_revision'=>$evaluation->policyRevision,'outcome'=>$evaluation->outcome->value,
                'rationale_json'=>$this->encode($evaluation->rationale->toArray()),'score_json'=>$this->encode($evaluation->score->toArray()),
                'failed_criteria_json'=>$this->encode($evaluation->failedCriteria),'reason'=>$evaluation->reason,
                'model_version'=>QualificationEvaluation::MODEL_VERSION,'evaluated_at'=>$evaluation->evaluatedAt->format('Y-m-d H:i:s.u'),
                'created_by'=>$actorId,
            ],
        );
    }

    public function viewEvaluation(string $organizationId,string $evaluationId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,evaluation_id,candidate_id,policy_id,policy_revision,outcome,rationale_json,score_json,
                    failed_criteria_json,reason,model_version,evaluated_at,created_by,created_at
             FROM tn_growth_candidate_evaluations
             WHERE organization_id=:organization_id AND evaluation_id=:evaluation_id LIMIT 1',
            ['organization_id'=>$organizationId,'evaluation_id'=>$evaluationId],
        );
        return $this->hydrateEvaluation($row);
    }

    public function latestEvaluation(string $organizationId,string $candidateId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,evaluation_id,candidate_id,policy_id,policy_revision,outcome,rationale_json,score_json,
                    failed_criteria_json,reason,model_version,evaluated_at,created_by,created_at
             FROM tn_growth_candidate_evaluations
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY evaluated_at DESC,evaluation_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        return $this->hydrateEvaluation($row);
    }

    /** @param array<string,mixed> $row */
    private function hydratePolicy(array $row): QualificationPolicy
    {
        $status=QualificationPolicyStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth qualification policy status is invalid.');
        return QualificationPolicy::restore(
            (string)$row['policy_id'],OrganizationId::fromString((string)$row['organization_id']),(int)$row['revision'],
            (string)$row['name'],QualificationPolicyCriteria::fromArray($this->decodeObject((string)$row['criteria_json'])),$status,
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateEvaluation(?array $row): ?array
    {
        if($row===null)return null;
        $row['rationale']=$this->decodeObject((string)$row['rationale_json']);
        $row['score']=$this->decodeObject((string)$row['score_json']);
        $failed=json_decode((string)$row['failed_criteria_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($failed)||!array_is_list($failed))throw new InvalidArgumentException('Stored Growth failed criteria are invalid.');
        $row['failed_criteria']=array_values(array_map('strval',$failed));
        unset($row['rationale_json'],$row['score_json'],$row['failed_criteria_json']);
        $row['policy_revision']=(int)$row['policy_revision'];
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

    private function encode(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $json): array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Stored Growth JSON object is invalid.');
        return $value;
    }
}
