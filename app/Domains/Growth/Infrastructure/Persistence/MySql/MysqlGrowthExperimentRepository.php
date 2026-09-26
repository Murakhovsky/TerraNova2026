<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthExperimentRepositoryInterface;
use Domains\Growth\Domain\GrowthExperiment;
use Domains\Growth\Domain\GrowthExperimentAssignment;
use Domains\Growth\Domain\GrowthExperimentAssignmentSource;
use Domains\Growth\Domain\GrowthExperimentDimension;
use Domains\Growth\Domain\GrowthExperimentStatus;
use Domains\Growth\Domain\GrowthExperimentVariant;
use Domains\Growth\Domain\GrowthOutcomeType;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthExperimentRepository implements GrowthExperimentRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function createExperiment(GrowthExperiment $experiment,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_experiments
             (organization_id,experiment_id,name,hypothesis,dimension,primary_outcome,variants_json,status,
              started_at,ended_at,created_by,updated_by,created_at,updated_at)
             VALUES(:organization_id,:experiment_id,:name,:hypothesis,:dimension,:primary_outcome,:variants_json,:status,
                    :started_at,:ended_at,:created_by,:updated_by,:created_at,:updated_at)',
            [
                'organization_id'=>$experiment->organizationId->value(),'experiment_id'=>$experiment->id,
                'name'=>$experiment->name,'hypothesis'=>$experiment->hypothesis,'dimension'=>$experiment->dimension->value,
                'primary_outcome'=>$experiment->primaryOutcome->value,
                'variants_json'=>$this->encode(array_map(static fn(GrowthExperimentVariant $v):array=>$v->toArray(),$experiment->variants)),
                'status'=>$experiment->status()->value,
                'started_at'=>$experiment->startedAt()?->format('Y-m-d H:i:s.u'),
                'ended_at'=>$experiment->endedAt()?->format('Y-m-d H:i:s.u'),
                'created_by'=>$actorId,'updated_by'=>$actorId,
                'created_at'=>$experiment->createdAt->format('Y-m-d H:i:s.u'),
                'updated_at'=>$experiment->createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    public function lockExperiment(string $organizationId,string $experimentId):GrowthExperiment
    {
        $row=$this->one(
            'SELECT organization_id,experiment_id,name,hypothesis,dimension,primary_outcome,variants_json,status,
                    started_at,ended_at,created_at
             FROM tn_growth_experiments
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'experiment_id'=>$experimentId],
        );
        if($row===null)throw new InvalidArgumentException('Growth experiment was not found.');
        return $this->hydrateExperiment($row);
    }

    public function updateExperiment(GrowthExperiment $experiment,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_experiments
             SET status=:status,started_at=:started_at,ended_at=:ended_at,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id'
        );
        $statement->execute([
            'status'=>$experiment->status()->value,
            'started_at'=>$experiment->startedAt()?->format('Y-m-d H:i:s.u'),
            'ended_at'=>$experiment->endedAt()?->format('Y-m-d H:i:s.u'),
            'updated_by'=>$actorId,'organization_id'=>$experiment->organizationId->value(),'experiment_id'=>$experiment->id,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth experiment update did not change exactly one row.');
    }

    public function viewExperiment(string $organizationId,string $experimentId):?array
    {
        $row=$this->one(
            'SELECT organization_id,experiment_id,name,hypothesis,dimension,primary_outcome,variants_json,status,
                    started_at,ended_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_experiments
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id LIMIT 1',
            ['organization_id'=>$organizationId,'experiment_id'=>$experimentId],
        );
        return $this->hydrateExperimentRow($row);
    }

    public function listExperiments(string $organizationId,array $filters=[],int $limit=100):array
    {
        $limit=max(1,min(300,$limit));
        $where=['organization_id=:organization_id'];
        $params=['organization_id'=>$organizationId];

        foreach(['status'=>24,'dimension'=>40] as $field=>$max){
            $value=$this->filter($filters,$field,$max);
            if($value!==null){
                $where[]=$field.'=:'.$field;
                $params[$field]=$value;
            }
        }
        $query=$this->filter($filters,'q',191);
        if($query!==null){
            $where[]='(experiment_id LIKE :q OR name LIKE :q OR hypothesis LIKE :q)';
            $params['q']='%'.$this->like($query).'%';
        }

        $statement=$this->connection->prepare(
            'SELECT organization_id,experiment_id,name,hypothesis,dimension,primary_outcome,variants_json,status,
                    started_at,ended_at,created_by,updated_by,created_at,updated_at
             FROM tn_growth_experiments
             WHERE '.implode(' AND ',$where).'
             ORDER BY updated_at DESC,experiment_id DESC
             LIMIT '.$limit
        );
        $statement->execute($params);
        $rows=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $hydrated=$this->hydrateExperimentRow($row);
            if($hydrated!==null)$rows[]=$hydrated;
        }
        return $rows;
    }

    public function candidateHasTerminalOutcome(string $organizationId,string $candidateId):bool
    {
        $statement=$this->connection->prepare(
            'SELECT 1 FROM tn_growth_outcomes
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
               AND outcome_type IN (\'won\',\'lost\',\'disqualified\')
             LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        return $statement->fetchColumn()!==false;
    }

    public function createAssignment(GrowthExperimentAssignment $assignment,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_experiment_assignments
             (organization_id,assignment_id,experiment_id,candidate_id,variant_key,assignment_source,
              context_snapshot_json,assigned_at,created_by,created_at)
             VALUES(:organization_id,:assignment_id,:experiment_id,:candidate_id,:variant_key,:assignment_source,
                    :context_snapshot_json,:assigned_at,:created_by,NOW(6))',
            [
                'organization_id'=>$assignment->organizationId->value(),'assignment_id'=>$assignment->id,
                'experiment_id'=>$assignment->experimentId,'candidate_id'=>$assignment->candidateId,
                'variant_key'=>$assignment->variantKey,'assignment_source'=>$assignment->source->value,
                'context_snapshot_json'=>$this->encode($assignment->contextSnapshot),
                'assigned_at'=>$assignment->assignedAt->format('Y-m-d H:i:s.u'),'created_by'=>$actorId,
            ],
        );
    }

    public function findAssignment(string $organizationId,string $experimentId,string $candidateId):?array
    {
        $row=$this->one(
            'SELECT organization_id,assignment_id,experiment_id,candidate_id,variant_key,assignment_source,
                    context_snapshot_json,assigned_at,created_by,created_at
             FROM tn_growth_experiment_assignments
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id AND candidate_id=:candidate_id
             LIMIT 1',
            ['organization_id'=>$organizationId,'experiment_id'=>$experimentId,'candidate_id'=>$candidateId],
        );
        return $this->hydrateAssignmentRow($row);
    }

    public function assignments(string $organizationId,string $experimentId,int $limit=500):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->prepare(
            'SELECT organization_id,assignment_id,experiment_id,candidate_id,variant_key,assignment_source,
                    context_snapshot_json,assigned_at,created_by,created_at
             FROM tn_growth_experiment_assignments
             WHERE organization_id=:organization_id AND experiment_id=:experiment_id
             ORDER BY assigned_at DESC,assignment_id DESC
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'experiment_id'=>$experimentId]);
        $rows=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $hydrated=$this->hydrateAssignmentRow($row);
            if($hydrated!==null)$rows[]=$hydrated;
        }
        return $rows;
    }

    public function attributionReport(string $organizationId,string $experimentId):array
    {
        $experiment=$this->viewExperiment($organizationId,$experimentId)
            ?? throw new InvalidArgumentException('Growth experiment was not found.');
        $endedAt=$experiment['ended_at']??null;
        $endFilter=is_string($endedAt)&&$endedAt!==''?' AND o.observed_at<=:ended_at':'';

        $statement=$this->connection->prepare(
            'SELECT a.variant_key,
                    COUNT(DISTINCT a.candidate_id) AS assigned_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'contacted\' THEN a.candidate_id END) AS contacted_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'qualified\' THEN a.candidate_id END) AS qualified_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'reply_received\' THEN a.candidate_id END) AS reply_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'meeting_completed\' THEN a.candidate_id END) AS meeting_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'won\' THEN a.candidate_id END) AS won_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'lost\' THEN a.candidate_id END) AS lost_count,
                    COUNT(DISTINCT CASE WHEN o.outcome_type=\'disqualified\' THEN a.candidate_id END) AS disqualified_count
             FROM tn_growth_experiment_assignments a
             LEFT JOIN tn_growth_outcomes o
               ON o.organization_id=a.organization_id
              AND o.candidate_id=a.candidate_id
              AND o.observed_at>=a.assigned_at'.$endFilter.'
             WHERE a.organization_id=:organization_id AND a.experiment_id=:experiment_id
             GROUP BY a.variant_key
             ORDER BY a.variant_key'
        );
        $params=['organization_id'=>$organizationId,'experiment_id'=>$experimentId];
        if($endFilter!=='')$params['ended_at']=$endedAt;
        $statement->execute($params);

        $metrics=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $assigned=(int)$row['assigned_count'];
            $metrics[(string)$row['variant_key']]=[
                'assigned'=>$assigned,
                'contacted'=>(int)$row['contacted_count'],
                'qualified'=>(int)$row['qualified_count'],
                'reply_received'=>(int)$row['reply_count'],
                'meeting_completed'=>(int)$row['meeting_count'],
                'won'=>(int)$row['won_count'],
                'lost'=>(int)$row['lost_count'],
                'disqualified'=>(int)$row['disqualified_count'],
            ];
        }

        $wonValue=$this->wonValueByVariant($organizationId,$experimentId,$endedAt);
        $primary=(string)$experiment['primary_outcome'];
        $variants=[];
        foreach($experiment['variants'] as $variant){
            $key=(string)$variant['key'];
            $metric=$metrics[$key]??[
                'assigned'=>0,'contacted'=>0,'qualified'=>0,'reply_received'=>0,'meeting_completed'=>0,
                'won'=>0,'lost'=>0,'disqualified'=>0,
            ];
            $assigned=(int)$metric['assigned'];
            $primaryCount=(int)($metric[$primary]??0);
            $variants[]=[
                'key'=>$key,
                'name'=>$variant['name'],
                'allocation_weight'=>$variant['allocation_weight'],
                'config'=>$variant['config'],
                'assigned'=>$assigned,
                'outcomes'=>[
                    'contacted'=>$metric['contacted'],'qualified'=>$metric['qualified'],
                    'reply_received'=>$metric['reply_received'],'meeting_completed'=>$metric['meeting_completed'],
                    'won'=>$metric['won'],'lost'=>$metric['lost'],'disqualified'=>$metric['disqualified'],
                ],
                'primary_outcome'=>$primary,
                'primary_count'=>$primaryCount,
                'primary_rate'=>$assigned<1?0.0:round($primaryCount/$assigned,4),
                'won_value_by_currency'=>$wonValue[$key]??[],
            ];
        }

        return [
            'experiment_id'=>$experimentId,
            'status'=>$experiment['status'],
            'dimension'=>$experiment['dimension'],
            'primary_outcome'=>$primary,
            'started_at'=>$experiment['started_at'],
            'ended_at'=>$experiment['ended_at'],
            'attribution_rule'=>'candidate_outcomes_after_assignment_until_experiment_end',
            'variants'=>$variants,
        ];
    }

    /** @return array<string,array<string,float>> */
    private function wonValueByVariant(string $organizationId,string $experimentId,mixed $endedAt):array
    {
        $endFilter=is_string($endedAt)&&$endedAt!==''?' AND o.observed_at<=:ended_at':'';
        $statement=$this->connection->prepare(
            'SELECT a.variant_key,o.currency,SUM(o.economic_value) AS total_value
             FROM tn_growth_experiment_assignments a
             INNER JOIN tn_growth_outcomes o
               ON o.organization_id=a.organization_id
              AND o.candidate_id=a.candidate_id
              AND o.outcome_type=\'won\'
              AND o.observed_at>=a.assigned_at'.$endFilter.'
              AND o.id=(
                  SELECT o2.id
                  FROM tn_growth_outcomes o2
                  WHERE o2.organization_id=a.organization_id
                    AND o2.candidate_id=a.candidate_id
                    AND o2.outcome_type=\'won\'
                    AND o2.observed_at>=a.assigned_at'
                    .($endFilter!==''?' AND o2.observed_at<=:ended_at2':'').'
                  ORDER BY o2.observed_at DESC,o2.id DESC
                  LIMIT 1
              )
             WHERE a.organization_id=:organization_id AND a.experiment_id=:experiment_id
               AND o.economic_value IS NOT NULL AND o.currency IS NOT NULL AND o.currency<>\'\'
             GROUP BY a.variant_key,o.currency
             ORDER BY a.variant_key,o.currency'
        );
        $params=['organization_id'=>$organizationId,'experiment_id'=>$experimentId];
        if($endFilter!==''){
            $params['ended_at']=$endedAt;
            $params['ended_at2']=$endedAt;
        }
        $statement->execute($params);
        $values=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $values[(string)$row['variant_key']][(string)$row['currency']]=(float)$row['total_value'];
        }
        return $values;
    }

    /** @param array<string,mixed> $row */
    private function hydrateExperiment(array $row):GrowthExperiment
    {
        $dimension=GrowthExperimentDimension::tryFrom((string)$row['dimension'])
            ?? throw new InvalidArgumentException('Stored Growth experiment dimension is invalid.');
        $primary=GrowthOutcomeType::tryFrom((string)$row['primary_outcome'])
            ?? throw new InvalidArgumentException('Stored Growth experiment primary outcome is invalid.');
        $status=GrowthExperimentStatus::tryFrom((string)$row['status'])
            ?? throw new InvalidArgumentException('Stored Growth experiment status is invalid.');
        $variants=[];
        foreach($this->decodeListOfObjects((string)$row['variants_json']) as $variant)$variants[]=GrowthExperimentVariant::fromArray($variant);

        return new GrowthExperiment(
            (string)$row['experiment_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['name'],(string)$row['hypothesis'],$dimension,$primary,$variants,
            new DateTimeImmutable((string)$row['created_at']),$status,
            $row['started_at']===null?null:new DateTimeImmutable((string)$row['started_at']),
            $row['ended_at']===null?null:new DateTimeImmutable((string)$row['ended_at']),
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateExperimentRow(?array $row):?array
    {
        if($row===null)return null;
        $row['variants']=$this->decodeListOfObjects((string)$row['variants_json']);
        unset($row['variants_json']);
        return $row;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateAssignmentRow(?array $row):?array
    {
        if($row===null)return null;
        $context=$this->decodeObject((string)$row['context_snapshot_json']);
        $row['context_snapshot']=$context;
        unset($row['context_snapshot_json']);
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

    /** @return array<string,mixed> */
    private function decodeObject(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('Stored Growth experiment JSON object is invalid.');
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function decodeListOfObjects(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth experiment JSON list is invalid.');
        foreach($value as $item)if(!is_array($item)||array_is_list($item))throw new InvalidArgumentException('Stored Growth experiment variant is invalid.');
        return $value;
    }

    /** @param array<string,mixed> $filters */
    private function filter(array $filters,string $key,int $limit):?string
    {
        $value=$filters[$key]??null;
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        return $value===''?null:mb_substr($value,0,$limit);
    }

    private function like(string $value):string
    {
        return strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']);
    }
}
