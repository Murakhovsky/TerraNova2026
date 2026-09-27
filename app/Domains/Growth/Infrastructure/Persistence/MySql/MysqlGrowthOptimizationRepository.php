<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthOptimizationRepositoryInterface;
use Domains\Growth\Domain\LearningOptimizationRecommendation;
use Domains\Growth\Domain\OptimizationRecommendationStatus;
use Domains\Growth\Domain\OptimizationTargetType;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthOptimizationRepository implements GrowthOptimizationRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function optimizationContext(string $organizationId,int $sampleLimit=200):array
    {
        $sampleLimit=max(8,min(500,$sampleLimit));
        $targets=$this->activeTargets($organizationId);
        $samples=$this->terminalSamples($organizationId,$sampleLimit);

        $outcomeCounts=[];
        $reasonCounts=[];
        $wonValues=[];
        $dimensionStats=[];
        $icpStats=['won'=>[],'other'=>[]];

        foreach(['fit','need','timing','access','value'] as $dimension){
            $dimensionStats[$dimension]=['won'=>[],'other'=>[]];
        }

        foreach($samples as $sample){
            $outcome=(string)$sample['outcome_type'];
            $outcomeCounts[$outcome]=($outcomeCounts[$outcome]??0)+1;
            $bucket=$outcome==='won'?'won':'other';

            $reason=trim((string)($sample['reason_code']??''));
            if($reason!=='')$reasonCounts[$outcome.':'.$reason]=($reasonCounts[$outcome.':'.$reason]??0)+1;

            if($outcome==='won'&&$sample['economic_value']!==null){
                $currency=(string)($sample['currency']??'');
                if($currency!=='')$wonValues[$currency]=($wonValues[$currency]??0.0)+(float)$sample['economic_value'];
            }

            $score=$sample['score'];
            if(is_array($score)){
                foreach(array_keys($dimensionStats) as $dimension){
                    $value=$score[$dimension]['score']??null;
                    if(is_int($value)||is_float($value))$dimensionStats[$dimension][$bucket][]=(float)$value;
                }
            }

            if($sample['latest_icp_fit_score']!==null)$icpStats[$bucket][]=(float)$sample['latest_icp_fit_score'];

        }

        arsort($reasonCounts,SORT_NUMERIC);
        $evidence=[];
        $evidence[]=[
            'id'=>'learning:terminal_outcomes',
            'kind'=>'terminal_outcomes',
            'sample_size'=>count($samples),
            'counts'=>$outcomeCounts,
            'win_rate'=>$this->rate((int)($outcomeCounts['won']??0),count($samples)),
        ];

        foreach($dimensionStats as $dimension=>$stats){
            if($stats['won']===[]||$stats['other']===[])continue;
            $wonAvg=$this->average($stats['won']);
            $otherAvg=$this->average($stats['other']);
            $evidence[]=[
                'id'=>'learning:score:'.$dimension,
                'kind'=>'score_dimension',
                'dimension'=>$dimension,
                'won_average'=>$wonAvg,
                'other_average'=>$otherAvg,
                'delta'=>$wonAvg-$otherAvg,
                'won_sample'=>count($stats['won']),
                'other_sample'=>count($stats['other']),
            ];
        }

        if($icpStats['won']!==[]&&$icpStats['other']!==[]){
            $wonAvg=$this->average($icpStats['won']);
            $otherAvg=$this->average($icpStats['other']);
            $evidence[]=[
                'id'=>'learning:icp_fit',
                'kind'=>'icp_fit',
                'won_average'=>$wonAvg,
                'other_average'=>$otherAvg,
                'delta'=>$wonAvg-$otherAvg,
                'won_sample'=>count($icpStats['won']),
                'other_sample'=>count($icpStats['other']),
            ];
        }

        $signalStats=$this->signalPerformance($organizationId,$sampleLimit);
        $signalCount=0;
        foreach($signalStats as $signalType=>$stats){
            if($stats['sample_count']<2)continue;
            $evidence[]=[
                'id'=>$this->metricId('signal',$signalType),
                'kind'=>'signal_type',
                'signal_type'=>$signalType,
                'sample_size'=>$stats['sample_count'],
                'won'=>$stats['won'],
                'other'=>$stats['other'],
                'win_rate'=>$this->rate($stats['won'],$stats['sample_count']),
            ];
            if(++$signalCount>=30)break;
        }

        $reasonItems=[];
        foreach($reasonCounts as $key=>$count){
            [$outcome,$reason]=explode(':',$key,2);
            $reasonItems[]=['outcome_type'=>$outcome,'reason_code'=>$reason,'count'=>$count];
            if(count($reasonItems)>=20)break;
        }
        if($reasonItems!==[]){
            $evidence[]=[
                'id'=>'learning:loss_reasons',
                'kind'=>'reason_distribution',
                'items'=>$reasonItems,
            ];
        }

        if($wonValues!==[]){
            ksort($wonValues,SORT_STRING);
            $evidence[]=[
                'id'=>'learning:won_value',
                'kind'=>'economic_outcome',
                'won_value_by_currency'=>$wonValues,
            ];
        }

        return [
            'minimum_terminal_sample'=>8,
            'terminal_sample_size'=>count($samples),
            'active_targets'=>$targets,
            'learning_summary'=>[
                'outcome_counts'=>$outcomeCounts,
                'won_value_by_currency'=>$wonValues,
                'top_reasons'=>$reasonItems,
            ],
            'evidence'=>$evidence,
            'allowed_evidence_ids'=>array_values(array_map(static fn(array $item):string=>(string)$item['id'],$evidence)),
        ];
    }

    public function createRun(
        string $organizationId,string $runId,array $contextSnapshot,string $promptVersion,string $schemaVersion,int $actorId
    ):void {
        $this->execute(
            'INSERT INTO tn_growth_optimization_runs
             (organization_id,run_id,status,context_snapshot_json,prompt_version,schema_version,created_by,started_at)
             VALUES(:organization_id,:run_id,\'running\',:context_snapshot_json,:prompt_version,:schema_version,:created_by,NOW(6))',
            [
                'organization_id'=>$organizationId,'run_id'=>$runId,'context_snapshot_json'=>$this->encode($contextSnapshot),
                'prompt_version'=>$promptVersion,'schema_version'=>$schemaVersion,'created_by'=>$actorId,
            ],
        );
    }

    public function completeRun(
        string $organizationId,string $runId,string $recommendationId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ):void {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_optimization_runs
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
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth optimization run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_optimization_runs
             SET status=\'failed\',error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute(['error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth optimization run could not be failed.');
    }

    public function viewRun(string $organizationId,string $runId):?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,status,context_snapshot_json,prompt_version,schema_version,recommendation_id,
                    provider,model,input_tokens,output_tokens,cost_amount,cost_currency,error_summary,
                    started_at,finished_at,created_by,created_at
             FROM tn_growth_optimization_runs
             WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        $context=json_decode((string)$row['context_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($context)||array_is_list($context))throw new InvalidArgumentException('Stored Growth optimization context is invalid.');
        $row['context_snapshot']=$context;
        unset($row['context_snapshot_json']);
        foreach(['input_tokens','output_tokens'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        return $row;
    }

    public function supersedeProposed(string $organizationId,string $reason,int $actorId):array
    {
        $statement=$this->connection->prepare(
            'SELECT recommendation_id FROM tn_growth_optimization_recommendations
             WHERE organization_id=:organization_id AND status=\'proposed\'
             ORDER BY created_at,recommendation_id FOR UPDATE'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $ids=array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
        if($ids===[])return [];

        $update=$this->connection->prepare(
            'UPDATE tn_growth_optimization_recommendations
             SET status=\'superseded\',decision_reason=:reason,decided_at=NOW(6),updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND status=\'proposed\''
        );
        $update->execute(['reason'=>$reason,'updated_by'=>$actorId,'organization_id'=>$organizationId]);
        return $ids;
    }

    public function createRecommendation(LearningOptimizationRecommendation $recommendation,string $runId,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_optimization_recommendations
             (organization_id,recommendation_id,run_id,target_type,target_id,base_revision,proposed_name,
              proposed_criteria_json,rationale,evidence_ids_json,risks_json,assumptions_json,confidence,
              provider,model,prompt_version,schema_version,status,decision_reason,materialized_revision,
              created_by,updated_by,created_at,updated_at)
             VALUES(:organization_id,:recommendation_id,:run_id,:target_type,:target_id,:base_revision,:proposed_name,
                    :proposed_criteria_json,:rationale,:evidence_ids_json,:risks_json,:assumptions_json,:confidence,
                    :provider,:model,:prompt_version,:schema_version,:status,:decision_reason,:materialized_revision,
                    :created_by,:updated_by,:created_at,:updated_at)',
            [
                'organization_id'=>$recommendation->organizationId->value(),'recommendation_id'=>$recommendation->id,'run_id'=>$runId,
                'target_type'=>$recommendation->targetType->value,'target_id'=>$recommendation->targetId,
                'base_revision'=>$recommendation->baseRevision,'proposed_name'=>$recommendation->proposedName,
                'proposed_criteria_json'=>$this->encode($recommendation->proposedCriteria),'rationale'=>$recommendation->rationale,
                'evidence_ids_json'=>$this->encode($recommendation->evidenceIds),'risks_json'=>$this->encode($recommendation->risks),
                'assumptions_json'=>$this->encode($recommendation->assumptions),'confidence'=>$recommendation->confidence,
                'provider'=>$recommendation->provider,'model'=>$recommendation->model,'prompt_version'=>$recommendation->promptVersion,
                'schema_version'=>$recommendation->schemaVersion,'status'=>$recommendation->status()->value,
                'decision_reason'=>$recommendation->decisionReason(),'materialized_revision'=>$recommendation->materializedRevision(),
                'created_by'=>$actorId,'updated_by'=>$actorId,'created_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at'=>$recommendation->createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    public function lockRecommendation(string $organizationId,string $recommendationId):LearningOptimizationRecommendation
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,target_type,target_id,base_revision,proposed_name,proposed_criteria_json,
                    rationale,evidence_ids_json,risks_json,assumptions_json,confidence,provider,model,prompt_version,
                    schema_version,status,decision_reason,materialized_revision,created_at
             FROM tn_growth_optimization_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        if($row===null)throw new InvalidArgumentException('Growth optimization recommendation was not found.');
        return $this->hydrate($row);
    }

    public function updateRecommendation(LearningOptimizationRecommendation $recommendation,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_optimization_recommendations
             SET status=:status,decision_reason=:decision_reason,materialized_revision=:materialized_revision,
                 decided_at=CASE WHEN :status2<>\'proposed\' THEN COALESCE(decided_at,NOW(6)) ELSE decided_at END,
                 materialized_at=CASE WHEN :status3=\'materialized\' THEN COALESCE(materialized_at,NOW(6)) ELSE materialized_at END,
                 updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id'
        );
        $statement->execute([
            'status'=>$recommendation->status()->value,'decision_reason'=>$recommendation->decisionReason(),
            'materialized_revision'=>$recommendation->materializedRevision(),'status2'=>$recommendation->status()->value,
            'status3'=>$recommendation->status()->value,'updated_by'=>$actorId,
            'organization_id'=>$recommendation->organizationId->value(),'recommendation_id'=>$recommendation->id,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth optimization recommendation update did not change exactly one row.');
    }

    public function viewRecommendation(string $organizationId,string $recommendationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,target_type,target_id,base_revision,proposed_name,
                    proposed_criteria_json,rationale,evidence_ids_json,risks_json,assumptions_json,confidence,
                    provider,model,prompt_version,schema_version,status,decision_reason,materialized_revision,
                    decided_at,materialized_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_optimization_recommendations
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
        return $this->hydrateRow($row);
    }

    public function latestRecommendation(string $organizationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,recommendation_id,run_id,target_type,target_id,base_revision,proposed_name,
                    proposed_criteria_json,rationale,evidence_ids_json,risks_json,assumptions_json,confidence,
                    provider,model,prompt_version,schema_version,status,decision_reason,materialized_revision,
                    decided_at,materialized_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_optimization_recommendations
             WHERE organization_id=:organization_id
             ORDER BY created_at DESC,recommendation_id DESC LIMIT 1',
            ['organization_id'=>$organizationId],
        );
        return $this->hydrateRow($row);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function activeTargets(string $organizationId):array
    {
        $icp=[];
        $statement=$this->connection->prepare(
            'SELECT profile_id,revision,name,criteria_json
             FROM tn_growth_icp_profiles
             WHERE organization_id=:organization_id AND status=\'active\'
             ORDER BY updated_at DESC,profile_id,revision DESC LIMIT 20'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $icp[]=[
                'target_type'=>'icp_profile','target_id'=>(string)$row['profile_id'],'revision'=>(int)$row['revision'],
                'name'=>(string)$row['name'],'criteria'=>$this->decodeObject((string)$row['criteria_json']),
            ];
        }

        $policies=[];
        $statement=$this->connection->prepare(
            'SELECT policy_id,revision,name,criteria_json
             FROM tn_growth_qualification_policies
             WHERE organization_id=:organization_id AND status=\'active\'
             ORDER BY updated_at DESC,policy_id,revision DESC LIMIT 20'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $policies[]=[
                'target_type'=>'qualification_policy','target_id'=>(string)$row['policy_id'],'revision'=>(int)$row['revision'],
                'name'=>(string)$row['name'],'criteria'=>$this->decodeObject((string)$row['criteria_json']),
            ];
        }

        return ['icp_profiles'=>$icp,'qualification_policies'=>$policies];
    }

    /** @return list<array<string,mixed>> */
    private function terminalSamples(string $organizationId,int $limit):array
    {
        $statement=$this->connection->prepare(
            'SELECT o.candidate_id,o.outcome_type,o.reason_code,o.economic_value,o.currency,o.observed_at,
                    c.opportunity_type,c.growth_mode,c.subject_type,c.subject_id,c.score_json,
                    (
                        SELECT m.fit_score FROM tn_growth_account_icp_matches m
                        WHERE c.subject_type=\'account\' AND m.organization_id=c.organization_id AND m.account_id=c.subject_id
                        ORDER BY m.scored_at DESC,m.id DESC LIMIT 1
                    ) AS latest_icp_fit_score
             FROM tn_growth_outcomes o
             INNER JOIN (
                 SELECT candidate_id,MAX(id) AS max_id
                 FROM tn_growth_outcomes
                 WHERE organization_id=:organization_latest
                   AND outcome_type IN (\'won\',\'lost\',\'disqualified\')
                 GROUP BY candidate_id
                 ORDER BY max_id DESC
                 LIMIT '.$limit.'
             ) latest ON latest.max_id=o.id
             INNER JOIN tn_growth_candidates c
               ON c.organization_id=o.organization_id AND c.candidate_id=o.candidate_id
             WHERE o.organization_id=:organization_main
             ORDER BY o.observed_at DESC,o.id DESC
             LIMIT '.$limit
        );
        $statement->execute(['organization_latest'=>$organizationId,'organization_main'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){
            $row['economic_value']=$row['economic_value']===null?null:(float)$row['economic_value'];
            $row['latest_icp_fit_score']=$row['latest_icp_fit_score']===null?null:(int)$row['latest_icp_fit_score'];
            $row['score']=$row['score_json']===null?null:$this->decodeObject((string)$row['score_json']);
            unset($row['score_json']);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,array{sample_count:int,won:int,other:int}> */
    private function signalPerformance(string $organizationId,int $limit):array
    {
        $limit=max(8,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT s.signal_type,
                    COUNT(DISTINCT latest.candidate_id) AS sample_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'won\' THEN latest.candidate_id END) AS won_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type<>\'won\' THEN latest.candidate_id END) AS other_count
             FROM (
                 SELECT candidate_id,MAX(id) AS max_id
                 FROM tn_growth_outcomes
                 WHERE organization_id=:organization_latest
                   AND outcome_type IN (\'won\',\'lost\',\'disqualified\')
                 GROUP BY candidate_id
                 ORDER BY max_id DESC
                 LIMIT '.$limit.'
             ) latest
             INNER JOIN tn_growth_outcomes o ON o.id=latest.max_id
             INNER JOIN tn_growth_candidate_signals cs
               ON cs.organization_id=o.organization_id AND cs.candidate_id=latest.candidate_id
             INNER JOIN tn_growth_signals s
               ON s.organization_id=cs.organization_id AND s.signal_id=cs.signal_id
             WHERE o.organization_id=:organization_main
             GROUP BY s.signal_type
             ORDER BY sample_count DESC,s.signal_type
             LIMIT 50'
        );
        $statement->execute(['organization_latest'=>$organizationId,'organization_main'=>$organizationId]);
        $stats=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $signalType=(string)$row['signal_type'];
            $stats[$signalType]=[
                'sample_count'=>(int)$row['sample_count'],
                'won'=>(int)$row['won_count'],
                'other'=>(int)$row['other_count'],
            ];
        }
        return $stats;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):LearningOptimizationRecommendation
    {
        $targetType=OptimizationTargetType::tryFrom((string)$row['target_type'])
            ?? throw new InvalidArgumentException('Stored Growth optimization target type is invalid.');
        $status=OptimizationRecommendationStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth optimization status is invalid.');

        return new LearningOptimizationRecommendation(
            (string)$row['recommendation_id'],OrganizationId::fromString((string)$row['organization_id']),
            $targetType,(string)$row['target_id'],(int)$row['base_revision'],(string)$row['proposed_name'],
            $this->decodeObject((string)$row['proposed_criteria_json']),(string)$row['rationale'],
            $this->decodeList((string)$row['evidence_ids_json']),$this->decodeList((string)$row['risks_json']),
            $this->decodeList((string)$row['assumptions_json']),(float)$row['confidence'],
            (string)$row['provider'],(string)$row['model'],(string)$row['prompt_version'],(string)$row['schema_version'],
            new DateTimeImmutable((string)$row['created_at']),$status,
            $row['decision_reason']===null?null:(string)$row['decision_reason'],
            $row['materialized_revision']===null?null:(int)$row['materialized_revision'],
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateRow(?array $row):?array
    {
        if($row===null)return null;
        $row['proposed_criteria']=$this->decodeObject((string)$row['proposed_criteria_json']);
        $row['evidence_ids']=$this->decodeList((string)$row['evidence_ids_json']);
        $row['risks']=$this->decodeList((string)$row['risks_json']);
        $row['assumptions']=$this->decodeList((string)$row['assumptions_json']);
        unset($row['proposed_criteria_json'],$row['evidence_ids_json'],$row['risks_json'],$row['assumptions_json']);
        $row['base_revision']=(int)$row['base_revision'];
        $row['materialized_revision']=$row['materialized_revision']===null?null:(int)$row['materialized_revision'];
        $row['confidence']=(float)$row['confidence'];
        return $row;
    }

    /** @param list<float> $values */
    private function average(array $values):float
    {
        return round(array_sum($values)/count($values),2);
    }

    private function rate(int $part,int $whole):float
    {
        return $whole<1?0.0:round($part/$whole,4);
    }

    private function metricId(string $kind,string $value):string
    {
        return 'learning:'.$kind.':'.substr(hash('sha256',$value),0,12);
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

    /** @return array<string,mixed> */
    private function decodeObject(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Stored Growth optimization JSON object is invalid.');
        return $value;
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth optimization JSON list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
