<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\EngagementRecommendationStatus;
use Domains\Growth\Domain\NextBestActionType;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthEngagementRepository implements GrowthEngagementRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function createRun(
        string $organizationId,string $runId,string $candidateId,array $contextSnapshot,
        string $promptVersion,string $schemaVersion,int $actorId
    ):void {
        $this->execute(
            'INSERT INTO tn_growth_engagement_runs
             (organization_id,run_id,candidate_id,status,context_snapshot_json,prompt_version,schema_version,created_by,started_at)
             VALUES(:organization_id,:run_id,:candidate_id,\'running\',:context_snapshot_json,:prompt_version,:schema_version,:created_by,NOW(6))',
            [
                'organization_id'=>$organizationId,'run_id'=>$runId,'candidate_id'=>$candidateId,
                'context_snapshot_json'=>$this->encode($contextSnapshot),'prompt_version'=>$promptVersion,
                'schema_version'=>$schemaVersion,'created_by'=>$actorId,
            ],
        );
    }

    public function completeRun(
        string $organizationId,string $runId,string $recommendationId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ):void {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_runs
             SET status=\'completed\',recommendation_id=:recommendation_id,provider=:provider,model=:model,
                 input_tokens=:input_tokens,output_tokens=:output_tokens,cost_amount=:cost_amount,cost_currency=:cost_currency,
                 finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'recommendation_id'=>$recommendationId,'provider'=>$provider,'model'=>$model,
            'input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens,'cost_amount'=>$costAmount,'cost_currency'=>$costCurrency,
            'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth engagement run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_runs
             SET status=\'failed\',error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute(['error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth engagement run could not be failed.');
    }

    public function viewRun(string $organizationId,string $runId):?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,candidate_id,status,context_snapshot_json,prompt_version,schema_version,
                    recommendation_id,provider,model,input_tokens,output_tokens,cost_amount,cost_currency,error_summary,
                    started_at,finished_at,created_by,created_at
             FROM tn_growth_engagement_runs
             WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        $context=json_decode((string)$row['context_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($context)||array_is_list($context))throw new InvalidArgumentException('Stored Growth engagement context is invalid.');
        $row['context_snapshot']=$context;
        unset($row['context_snapshot_json']);
        foreach(['input_tokens','output_tokens'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        return $row;
    }

    public function supersedeProposedForCandidate(
        string $organizationId,string $candidateId,string $reason,int $actorId
    ):array {
        $statement=$this->connection->prepare(
            'SELECT recommendation_id FROM tn_growth_engagement_recommendations
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id AND status=\'proposed\'
             ORDER BY created_at,recommendation_id FOR UPDATE'
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        $ids=array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
        if($ids===[])return [];

        $update=$this->connection->prepare(
            'UPDATE tn_growth_engagement_recommendations
             SET status=\'superseded\',decision_reason=:reason,decided_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id AND status=\'proposed\''
        );
        $update->execute([
            'reason'=>$reason,'updated_by'=>$actorId,'organization_id'=>$organizationId,'candidate_id'=>$candidateId,
        ]);
        return $ids;
    }

    public function createRecommendation(EngagementRecommendation $recommendation,string $runId,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_engagement_recommendations
             (organization_id,recommendation_id,run_id,candidate_id,action_type,channel,contact_id,rationale,message_angle,
              evidence_ids_json,unknowns_json,confidence,provider,model,prompt_version,schema_version,status,decision_reason,
              created_by,updated_by,created_at,updated_at)
             VALUES(:organization_id,:recommendation_id,:run_id,:candidate_id,:action_type,:channel,:contact_id,:rationale,:message_angle,
                    :evidence_ids_json,:unknowns_json,:confidence,:provider,:model,:prompt_version,:schema_version,:status,:decision_reason,
                    :created_by,:updated_by,:created_at,:updated_at)',
            [
                'organization_id'=>$recommendation->organizationId->value(),'recommendation_id'=>$recommendation->id,'run_id'=>$runId,
                'candidate_id'=>$recommendation->candidateId,'action_type'=>$recommendation->actionType->value,
                'channel'=>$recommendation->channel->value,'contact_id'=>$recommendation->contactId,
                'rationale'=>$recommendation->rationale,'message_angle'=>$recommendation->messageAngle,
                'evidence_ids_json'=>$this->encode($recommendation->evidenceIds),'unknowns_json'=>$this->encode($recommendation->unknowns),
                'confidence'=>$recommendation->confidence,'provider'=>$recommendation->provider,'model'=>$recommendation->model,
                'prompt_version'=>$recommendation->promptVersion,'schema_version'=>$recommendation->schemaVersion,
                'status'=>$recommendation->status()->value,'decision_reason'=>$recommendation->decisionReason(),
                'created_by'=>$actorId,'updated_by'=>$actorId,'created_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    public function lockRecommendation(string $organizationId,string $recommendationId):EngagementRecommendation
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,candidate_id,action_type,channel,contact_id,rationale,message_angle,
                    evidence_ids_json,unknowns_json,confidence,provider,model,prompt_version,schema_version,status,
                    decision_reason,created_at
             FROM tn_growth_engagement_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        if($row===null)throw new InvalidArgumentException('Growth engagement recommendation was not found.');
        return $this->hydrate($row);
    }

    public function updateRecommendation(EngagementRecommendation $recommendation,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_recommendations
             SET status=:status,decision_reason=:decision_reason,decided_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id AND status=\'proposed\''
        );
        $statement->execute([
            'status'=>$recommendation->status()->value,'decision_reason'=>$recommendation->decisionReason(),'updated_by'=>$actorId,
            'organization_id'=>$recommendation->organizationId->value(),'recommendation_id'=>$recommendation->id,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth engagement recommendation could not be decided.');
    }

    public function viewRecommendation(string $organizationId,string $recommendationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,candidate_id,action_type,channel,contact_id,rationale,message_angle,
                    evidence_ids_json,unknowns_json,confidence,provider,model,prompt_version,schema_version,status,
                    decision_reason,decided_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_engagement_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        return $this->hydrateRow($row);
    }

    public function latestRecommendation(string $organizationId,string $candidateId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,candidate_id,action_type,channel,contact_id,rationale,message_angle,
                    evidence_ids_json,unknowns_json,confidence,provider,model,prompt_version,schema_version,status,
                    decision_reason,decided_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_engagement_recommendations
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY created_at DESC,recommendation_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        return $this->hydrateRow($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):EngagementRecommendation
    {
        $actionType=NextBestActionType::tryFrom((string)$row['action_type'])
            ?? throw new InvalidArgumentException('Stored Growth engagement action type is invalid.');
        $channel=EngagementChannel::tryFrom((string)$row['channel'])
            ?? throw new InvalidArgumentException('Stored Growth engagement channel is invalid.');
        $status=EngagementRecommendationStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth engagement status is invalid.');

        return new EngagementRecommendation(
            (string)$row['recommendation_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['candidate_id'],$actionType,$channel,
            $row['contact_id']===null?null:(string)$row['contact_id'],(string)$row['rationale'],(string)$row['message_angle'],
            $this->decodeList((string)$row['evidence_ids_json']),$this->decodeList((string)$row['unknowns_json']),
            (float)$row['confidence'],(string)$row['provider'],(string)$row['model'],
            (string)$row['prompt_version'],(string)$row['schema_version'],new DateTimeImmutable((string)$row['created_at']),
            $status,$row['decision_reason']===null?null:(string)$row['decision_reason'],
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateRow(?array $row):?array
    {
        if($row===null)return null;
        $row['evidence_ids']=$this->decodeList((string)$row['evidence_ids_json']);
        $row['unknowns']=$this->decodeList((string)$row['unknowns_json']);
        unset($row['evidence_ids_json'],$row['unknowns_json']);
        $row['confidence']=(float)$row['confidence'];
        return $row;
    }

    private function execute(string $sql,array $params):void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth engagement list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
