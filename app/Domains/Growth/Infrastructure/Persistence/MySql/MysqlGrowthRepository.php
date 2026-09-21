<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\OpportunityType;
use Domains\Growth\Domain\Signal;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthRepository implements GrowthRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createSignal(Signal $signal,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_signals
             (organization_id,signal_id,subject_type,subject_id,signal_type,facts_json,source_reference,
              confidence,occurred_at,detected_at,created_by)
             VALUES(:organization_id,:signal_id,:subject_type,:subject_id,:signal_type,:facts_json,:source_reference,
                    :confidence,:occurred_at,:detected_at,:created_by)',
            [
                'organization_id'=>$signal->organizationId->value(),'signal_id'=>$signal->id,
                'subject_type'=>$signal->subjectType,'subject_id'=>$signal->subjectId,'signal_type'=>$signal->signalType,
                'facts_json'=>$this->encode($signal->facts),'source_reference'=>$signal->sourceReference,
                'confidence'=>$signal->confidence,'occurred_at'=>$signal->occurredAt->format('Y-m-d H:i:s.u'),
                'detected_at'=>$signal->detectedAt->format('Y-m-d H:i:s.u'),'created_by'=>$actorId,
            ],
        );
    }

    public function viewSignal(string $organizationId,string $signalId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,signal_id,subject_type,subject_id,signal_type,facts_json,source_reference,
                    confidence,occurred_at,detected_at,created_by,created_at
             FROM tn_growth_signals
             WHERE organization_id=:organization_id AND signal_id=:signal_id LIMIT 1',
            ['organization_id'=>$organizationId,'signal_id'=>$signalId],
        );
        if($row===null)return null;
        $row['facts']=$this->decodeObject((string)$row['facts_json']);
        unset($row['facts_json']);
        $row['confidence']=(float)$row['confidence'];
        return $row;
    }

    public function createCandidate(OpportunityCandidate $candidate,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_candidates
             (organization_id,candidate_id,opportunity_type,growth_mode,subject_type,subject_id,target_domain,status,
              rationale_json,score_json,qualification_reason,expected_value,recommended_play,recommended_action,
              created_by,updated_by)
             VALUES(:organization_id,:candidate_id,:opportunity_type,:growth_mode,:subject_type,:subject_id,:target_domain,:status,
                    :rationale_json,:score_json,:qualification_reason,:expected_value,:recommended_play,:recommended_action,
                    :created_by,:updated_by)',
            $this->candidateParams($candidate,$actorId,true),
        );
        foreach($candidate->signalIds() as $signalId){
            $this->execute(
                'INSERT INTO tn_growth_candidate_signals(organization_id,candidate_id,signal_id)
                 VALUES(:organization_id,:candidate_id,:signal_id)',
                ['organization_id'=>$candidate->organizationId->value(),'candidate_id'=>$candidate->id,'signal_id'=>$signalId],
            );
        }
    }

    public function lockCandidate(string $organizationId,string $candidateId): OpportunityCandidate
    {
        $row=$this->one(
            'SELECT organization_id,candidate_id,opportunity_type,growth_mode,subject_type,subject_id,target_domain,status,
                    rationale_json,score_json,qualification_reason,expected_value,recommended_play,recommended_action
             FROM tn_growth_candidates
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        if($row===null)throw new InvalidArgumentException('Growth candidate was not found.');
        return $this->hydrateCandidate($row,$this->signalIds($organizationId,$candidateId));
    }

    public function updateCandidate(OpportunityCandidate $candidate,int $actorId): void
    {
        $params=$this->candidateParams($candidate,$actorId,false);
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_candidates
             SET status=:status,rationale_json=:rationale_json,score_json=:score_json,
                 qualification_reason=:qualification_reason,expected_value=:expected_value,
                 recommended_play=:recommended_play,recommended_action=:recommended_action,
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id'
        );
        $statement->execute([
            'status'=>$params['status'],'rationale_json'=>$params['rationale_json'],'score_json'=>$params['score_json'],
            'qualification_reason'=>$params['qualification_reason'],'expected_value'=>$params['expected_value'],
            'recommended_play'=>$params['recommended_play'],'recommended_action'=>$params['recommended_action'],
            'updated_by'=>$actorId,'organization_id'=>$params['organization_id'],'candidate_id'=>$params['candidate_id'],
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth candidate update did not change exactly one row.');
    }

    public function viewCandidate(string $organizationId,string $candidateId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,candidate_id,opportunity_type,growth_mode,subject_type,subject_id,target_domain,status,
                    rationale_json,score_json,qualification_reason,expected_value,recommended_play,recommended_action,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_candidates
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        if($row===null)return null;
        $row['signal_ids']=$this->signalIds($organizationId,$candidateId);
        $row['rationale']=$row['rationale_json']===null?null:$this->decodeObject((string)$row['rationale_json']);
        $row['score']=$row['score_json']===null?null:$this->decodeObject((string)$row['score_json']);
        unset($row['rationale_json'],$row['score_json']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function candidateParams(OpportunityCandidate $candidate,int $actorId,bool $includeCreated): array
    {
        $params=[
            'organization_id'=>$candidate->organizationId->value(),'candidate_id'=>$candidate->id,
            'opportunity_type'=>$candidate->type->value,'growth_mode'=>$candidate->mode->value,
            'subject_type'=>$candidate->subjectType,'subject_id'=>$candidate->subjectId,'target_domain'=>$candidate->targetDomain,
            'status'=>$candidate->status()->value,
            'rationale_json'=>$candidate->rationale()===null?null:$this->encode($candidate->rationale()->toArray()),
            'score_json'=>$candidate->score()===null?null:$this->encode($candidate->score()->toArray()),
            'qualification_reason'=>$candidate->qualificationReason(),'expected_value'=>$candidate->expectedValue(),
            'recommended_play'=>$candidate->recommendedPlay(),'recommended_action'=>$candidate->recommendedAction(),
            'updated_by'=>$actorId,
        ];
        if($includeCreated)$params['created_by']=$actorId;
        return $params;
    }

    /** @param array<string,mixed> $row @param list<string> $signalIds */
    private function hydrateCandidate(array $row,array $signalIds): OpportunityCandidate
    {
        $type=OpportunityType::tryFrom((string)$row['opportunity_type'])
            ?? throw new InvalidArgumentException('Stored Growth opportunity type is invalid.');
        $mode=GrowthMode::tryFrom((string)$row['growth_mode'])
            ?? throw new InvalidArgumentException('Stored Growth mode is invalid.');
        $status=OpportunityCandidateStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth candidate status is invalid.');
        $rationale=$row['rationale_json']===null?null:OpportunityRationale::fromArray($this->decodeObject((string)$row['rationale_json']));
        $score=$row['score_json']===null?null:OpportunityScore::fromArray($this->decodeObject((string)$row['score_json']));

        return OpportunityCandidate::restore(
            (string)$row['candidate_id'],OrganizationId::fromString((string)$row['organization_id']),
            $type,$mode,(string)$row['subject_type'],(string)$row['subject_id'],(string)$row['target_domain'],
            $signalIds,$status,$rationale,$score,$row['qualification_reason']===null?null:(string)$row['qualification_reason'],
            $row['expected_value']===null?null:(string)$row['expected_value'],
            $row['recommended_play']===null?null:(string)$row['recommended_play'],
            $row['recommended_action']===null?null:(string)$row['recommended_action'],
        );
    }

    /** @return list<string> */
    private function signalIds(string $organizationId,string $candidateId): array
    {
        $statement=$this->connection->prepare(
            'SELECT signal_id FROM tn_growth_candidate_signals
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id ORDER BY signal_id'
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
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

    /** @param array<string,mixed> $value */
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
