<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionRepositoryInterface;
use Domains\Growth\Domain\ExperimentDecisionRecommendation;
use Domains\Growth\Domain\ExperimentDecisionRecommendationStatus;
use Domains\Growth\Domain\ExperimentDecisionType;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthExperimentDecisionRepository implements GrowthExperimentDecisionRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function createRun(
        string $organizationId,string $runId,string $experimentId,array $contextSnapshot,
        string $promptVersion,string $schemaVersion,int $actorId
    ):void {
        $this->execute(
            'INSERT INTO tn_growth_experiment_decision_runs
             (organization_id,run_id,experiment_id,status,context_snapshot_json,prompt_version,schema_version,created_by,started_at)
             VALUES(:organization_id,:run_id,:experiment_id,\'running\',:context_snapshot_json,:prompt_version,:schema_version,:created_by,NOW(6))',
            [
                'organization_id'=>$organizationId,'run_id'=>$runId,'experiment_id'=>$experimentId,
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
            'UPDATE tn_growth_experiment_decision_runs
             SET status=\'completed\',recommendation_id=:recommendation_id,provider=:provider,model=:model,
                 input_tokens=:input_tokens,output_tokens=:output_tokens,cost_amount=:cost_amount,cost_currency=:cost_currency,
                 finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'recommendation_id'=>$recommendationId,'provider'=>$provider,'model'=>$model,
            'input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens,'cost_amount'=>$costAmount,
            'cost_currency'=>$costCurrency,'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth experiment decision run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_experiment_decision_runs
             SET status=\'failed\',error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute(['error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth experiment decision run could not be failed.');
    }

    public function viewRun(string $organizationId,string $runId):?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,experiment_id,status,context_snapshot_json,prompt_version,schema_version,
                    recommendation_id,provider,model,input_tokens,output_tokens,cost_amount,cost_currency,error_summary,
                    started_at,finished_at,created_by,created_at
             FROM tn_growth_experiment_decision_runs
             WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        $context=json_decode((string)$row['context_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($context)||array_is_list($context)){
            throw new InvalidArgumentException('Stored Growth experiment decision context is invalid.');
        }
        $row['context_snapshot']=$context;
        unset($row['context_snapshot_json']);
        foreach(['input_tokens','output_tokens'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        return $row;
    }

    public function supersedeProposedForExperiment(
        string $organizationId,string $experimentId,string $reason,int $actorId
    ):array {
        $statement=$this->connection->prepare(
            'SELECT recommendation_id
             FROM tn_growth_experiment_decision_recommendations
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id AND status=\'proposed\'
             ORDER BY created_at,recommendation_id FOR UPDATE'
        );
        $statement->execute(['organization_id'=>$organizationId,'experiment_id'=>$experimentId]);
        $ids=array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
        if($ids===[])return [];

        $update=$this->connection->prepare(
            'UPDATE tn_growth_experiment_decision_recommendations
             SET status=\'superseded\',decision_reason=:reason,decided_at=NOW(6),
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id AND status=\'proposed\''
        );
        $update->execute([
            'reason'=>$reason,'updated_by'=>$actorId,
            'organization_id'=>$organizationId,'experiment_id'=>$experimentId,
        ]);
        return $ids;
    }

    public function createRecommendation(
        ExperimentDecisionRecommendation $recommendation,string $runId,int $actorId
    ):void {
        $this->execute(
            'INSERT INTO tn_growth_experiment_decision_recommendations
             (organization_id,recommendation_id,run_id,experiment_id,decision_type,promoted_variant_key,
              rationale,evidence_ids_json,risks_json,assumptions_json,confidence,provider,model,prompt_version,
              schema_version,status,decision_reason,created_by,updated_by,created_at,updated_at)
             VALUES(:organization_id,:recommendation_id,:run_id,:experiment_id,:decision_type,:promoted_variant_key,
                    :rationale,:evidence_ids_json,:risks_json,:assumptions_json,:confidence,:provider,:model,:prompt_version,
                    :schema_version,:status,:decision_reason,:created_by,:updated_by,:created_at,:updated_at)',
            [
                'organization_id'=>$recommendation->organizationId->value(),
                'recommendation_id'=>$recommendation->id,'run_id'=>$runId,
                'experiment_id'=>$recommendation->experimentId,
                'decision_type'=>$recommendation->decisionType->value,
                'promoted_variant_key'=>$recommendation->promotedVariantKey,
                'rationale'=>$recommendation->rationale,
                'evidence_ids_json'=>$this->encode($recommendation->evidenceIds),
                'risks_json'=>$this->encode($recommendation->risks),
                'assumptions_json'=>$this->encode($recommendation->assumptions),
                'confidence'=>$recommendation->confidence,'provider'=>$recommendation->provider,
                'model'=>$recommendation->model,'prompt_version'=>$recommendation->promptVersion,
                'schema_version'=>$recommendation->schemaVersion,'status'=>$recommendation->status()->value,
                'decision_reason'=>$recommendation->decisionReason(),
                'created_by'=>$actorId,'updated_by'=>$actorId,
                'created_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    public function lockRecommendation(
        string $organizationId,string $recommendationId
    ):ExperimentDecisionRecommendation {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,experiment_id,decision_type,promoted_variant_key,
                    rationale,evidence_ids_json,risks_json,assumptions_json,confidence,provider,model,
                    prompt_version,schema_version,status,decision_reason,created_at
             FROM tn_growth_experiment_decision_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        if($row===null)throw new InvalidArgumentException('Growth experiment decision recommendation was not found.');
        return $this->hydrate($row);
    }

    public function updateRecommendation(
        ExperimentDecisionRecommendation $recommendation,int $actorId
    ):void {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_experiment_decision_recommendations
             SET status=:status,decision_reason=:decision_reason,
                 decided_at=CASE WHEN :status2<>\'proposed\' THEN COALESCE(decided_at,NOW(6)) ELSE decided_at END,
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id'
        );
        $statement->execute([
            'status'=>$recommendation->status()->value,
            'decision_reason'=>$recommendation->decisionReason(),
            'status2'=>$recommendation->status()->value,
            'updated_by'=>$actorId,
            'organization_id'=>$recommendation->organizationId->value(),
            'recommendation_id'=>$recommendation->id,
        ]);
        if($statement->rowCount()!==1){
            throw new InvalidArgumentException('Growth experiment decision recommendation update did not change exactly one row.');
        }
    }

    public function viewRecommendation(string $organizationId,string $recommendationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,experiment_id,decision_type,promoted_variant_key,
                    rationale,evidence_ids_json,risks_json,assumptions_json,confidence,provider,model,
                    prompt_version,schema_version,status,decision_reason,decided_at,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_experiment_decision_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        return $this->hydrateRow($row);
    }

    public function latestRecommendation(string $organizationId,string $experimentId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,experiment_id,decision_type,promoted_variant_key,
                    rationale,evidence_ids_json,risks_json,assumptions_json,confidence,provider,model,
                    prompt_version,schema_version,status,decision_reason,decided_at,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_experiment_decision_recommendations
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id
             ORDER BY created_at DESC,recommendation_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'experiment_id'=>$experimentId],
        );
        return $this->hydrateRow($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):ExperimentDecisionRecommendation
    {
        $decisionType=ExperimentDecisionType::tryFrom((string)$row['decision_type'])
            ?? throw new InvalidArgumentException('Stored Growth experiment decision type is invalid.');
        $status=ExperimentDecisionRecommendationStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth experiment decision status is invalid.');

        return new ExperimentDecisionRecommendation(
            (string)$row['recommendation_id'],
            OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['experiment_id'],$decisionType,
            $row['promoted_variant_key']===null?null:(string)$row['promoted_variant_key'],
            (string)$row['rationale'],
            $this->decodeList((string)$row['evidence_ids_json']),
            $this->decodeList((string)$row['risks_json']),
            $this->decodeList((string)$row['assumptions_json']),
            (float)$row['confidence'],(string)$row['provider'],(string)$row['model'],
            (string)$row['prompt_version'],(string)$row['schema_version'],
            new DateTimeImmutable((string)$row['created_at']),$status,
            $row['decision_reason']===null?null:(string)$row['decision_reason'],
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateRow(?array $row):?array
    {
        if($row===null)return null;
        $row['evidence_ids']=$this->decodeList((string)$row['evidence_ids_json']);
        $row['risks']=$this->decodeList((string)$row['risks_json']);
        $row['assumptions']=$this->decodeList((string)$row['assumptions_json']);
        unset($row['evidence_ids_json'],$row['risks_json'],$row['assumptions_json']);
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
        return json_encode(
            $value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value)){
            throw new InvalidArgumentException('Stored Growth experiment decision list is invalid.');
        }
        return array_values(array_map('strval',$value));
    }
}
