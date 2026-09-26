<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthOutreachSequenceRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthOutreachSequenceRepository implements GrowthOutreachSequenceRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function latestProfile(string $organizationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,profile_id,revision,enabled,allowed_channels_json,max_touches,follow_up_delay_hours,
                    max_advances_per_run,activation_started_at,reason,created_by,created_at
             FROM tn_growth_engagement_sequence_profiles
             WHERE organization_id=:organization_id ORDER BY revision DESC LIMIT 1',
            ['organization_id'=>$organizationId],
        );
        if($row===null)return null;
        $row['revision']=(int)$row['revision'];
        $row['enabled']=(bool)$row['enabled'];
        $row['max_touches']=(int)$row['max_touches'];
        $row['follow_up_delay_hours']=(int)$row['follow_up_delay_hours'];
        $row['max_advances_per_run']=(int)$row['max_advances_per_run'];
        $row['created_by']=(int)$row['created_by'];
        $row['allowed_channels']=$this->decodeList((string)$row['allowed_channels_json']);
        unset($row['allowed_channels_json']);
        return $row;
    }

    public function appendProfile(array $profile):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_sequence_profiles
             (organization_id,profile_id,revision,enabled,allowed_channels_json,max_touches,follow_up_delay_hours,
              max_advances_per_run,activation_started_at,reason,created_by,created_at)
             VALUES(:organization_id,:profile_id,:revision,:enabled,:allowed_channels_json,:max_touches,:follow_up_delay_hours,
                    :max_advances_per_run,:activation_started_at,:reason,:created_by,:created_at)'
        );
        $statement->execute([
            'organization_id'=>$profile['organization_id'],
            'profile_id'=>$profile['profile_id'],
            'revision'=>$profile['revision'],
            'enabled'=>!empty($profile['enabled'])?1:0,
            'allowed_channels_json'=>$this->encode($profile['allowed_channels']),
            'max_touches'=>$profile['max_touches'],
            'follow_up_delay_hours'=>$profile['follow_up_delay_hours'],
            'max_advances_per_run'=>$profile['max_advances_per_run'],
            'activation_started_at'=>$profile['activation_started_at'],
            'reason'=>$profile['reason'],
            'created_by'=>$profile['created_by'],
            'created_at'=>$profile['created_at'],
        ]);
    }

    public function schedulerOrganizations(int $limit):array
    {
        $limit=max(1,min(1000,$limit));
        $statement=$this->connection->query(
            'SELECT organization_id
             FROM (
               SELECT p.organization_id
               FROM tn_growth_engagement_sequence_profiles p
               INNER JOIN (
                 SELECT organization_id,MAX(revision) AS revision
                 FROM tn_growth_engagement_sequence_profiles GROUP BY organization_id
               ) latest ON latest.organization_id=p.organization_id AND latest.revision=p.revision
               WHERE p.enabled=1 AND p.activation_started_at IS NOT NULL
               UNION
               SELECT DISTINCT organization_id
               FROM tn_growth_engagement_sequences
               WHERE status=\'active\'
             ) scheduler_targets
             ORDER BY organization_id
             LIMIT '.$limit
        );
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
    }

    public function initialCandidates(string $organizationId,int $limit):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT e.execution_id,e.candidate_id,e.recommendation_id,e.target_reference_id AS contact_id,
                    e.channel,e.created_at,r.confidence
             FROM tn_growth_engagement_execution_links e
             INNER JOIN tn_growth_engagement_recommendations r
               ON r.organization_id=e.organization_id AND r.recommendation_id=e.recommendation_id
             INNER JOIN (
               SELECT organization_id,MAX(revision) AS revision
               FROM tn_growth_engagement_sequence_profiles GROUP BY organization_id
             ) p_latest ON p_latest.organization_id=e.organization_id
             INNER JOIN tn_growth_engagement_sequence_profiles policy
               ON policy.organization_id=p_latest.organization_id AND policy.revision=p_latest.revision
             LEFT JOIN tn_growth_engagement_sequences sq
               ON sq.organization_id=e.organization_id AND sq.root_recommendation_id=e.recommendation_id
             WHERE e.organization_id=:organization_id
               AND e.target_domain=\'growth\'
               AND e.target_reference_type=\'growth_contact\'
               AND r.status=\'accepted\'
               AND policy.enabled=1
               AND policy.activation_started_at IS NOT NULL
               AND e.created_at>=policy.activation_started_at
               AND JSON_CONTAINS(policy.allowed_channels_json,JSON_QUOTE(e.channel))
               AND sq.sequence_id IS NULL
             ORDER BY e.created_at,e.execution_id
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['confidence']=(float)$row['confidence'];
        unset($row);
        return array_values($rows);
    }

    public function createSequence(array $sequence):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_sequences
             (organization_id,sequence_id,candidate_id,root_recommendation_id,contact_id,channel,status,touch_count,max_touches,
              last_recommendation_id,next_due_at,stop_code,stop_reason,started_by,started_at,updated_at,finished_at)
             VALUES(:organization_id,:sequence_id,:candidate_id,:root_recommendation_id,:contact_id,:channel,\'active\',1,:max_touches,
                    :last_recommendation_id,NULL,NULL,NULL,:started_by,:started_at,:updated_at,NULL)'
        );
        $statement->execute([
            'organization_id'=>$sequence['organization_id'],
            'sequence_id'=>$sequence['sequence_id'],
            'candidate_id'=>$sequence['candidate_id'],
            'root_recommendation_id'=>$sequence['root_recommendation_id'],
            'contact_id'=>$sequence['contact_id'],
            'channel'=>$sequence['channel'],
            'max_touches'=>$sequence['max_touches'],
            'last_recommendation_id'=>$sequence['last_recommendation_id'],
            'started_by'=>$sequence['started_by'],
            'started_at'=>$sequence['started_at'],
            'updated_at'=>$sequence['updated_at'],
        ]);
    }

    public function sequenceByRootRecommendation(string $organizationId,string $rootRecommendationId):?array
    {
        return $this->sequenceRow(
            'SELECT * FROM tn_growth_engagement_sequences
             WHERE organization_id=:organization_id AND root_recommendation_id=:root_recommendation_id LIMIT 1',
            ['organization_id'=>$organizationId,'root_recommendation_id'=>$rootRecommendationId],
        );
    }

    public function sequenceForRecommendation(string $organizationId,string $recommendationId):?array
    {
        return $this->sequenceRow(
            'SELECT sq.*
             FROM tn_growth_engagement_sequence_steps st
             INNER JOIN tn_growth_engagement_sequences sq
               ON sq.organization_id=st.organization_id AND sq.sequence_id=st.sequence_id
             WHERE st.organization_id=:organization_id AND st.recommendation_id=:recommendation_id
             LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
    }

    public function latestForCandidate(string $organizationId,string $candidateId):?array
    {
        return $this->sequenceRow(
            'SELECT * FROM tn_growth_engagement_sequences
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY started_at DESC,sequence_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
    }

    public function activeSequences(string $organizationId,int $limit):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_growth_engagement_sequences
             WHERE organization_id=:organization_id AND status=\'active\'
             ORDER BY COALESCE(next_due_at,started_at),sequence_id
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map(fn(array $row):array=>$this->hydrateSequence($row),$statement->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }

    public function lockSequence(string $organizationId,string $sequenceId):array
    {
        return $this->sequenceRow(
            'SELECT * FROM tn_growth_engagement_sequences
             WHERE organization_id=:organization_id AND sequence_id=:sequence_id LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'sequence_id'=>$sequenceId],
        )??throw new InvalidArgumentException('Growth outreach sequence was not found.');
    }

    public function sequenceStatusForRecommendation(string $organizationId,string $recommendationId):?string
    {
        $sequence=$this->sequenceForRecommendation($organizationId,$recommendationId);
        return $sequence===null?null:(string)$sequence['status'];
    }

    public function appendStep(array $step):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_sequence_steps
             (organization_id,step_id,sequence_id,touch_number,recommendation_id,parent_recommendation_id,created_by,created_at)
             VALUES(:organization_id,:step_id,:sequence_id,:touch_number,:recommendation_id,:parent_recommendation_id,:created_by,:created_at)'
        );
        $statement->execute([
            'organization_id'=>$step['organization_id'],
            'step_id'=>$step['step_id'],
            'sequence_id'=>$step['sequence_id'],
            'touch_number'=>$step['touch_number'],
            'recommendation_id'=>$step['recommendation_id'],
            'parent_recommendation_id'=>$step['parent_recommendation_id'],
            'created_by'=>$step['created_by'],
            'created_at'=>$step['created_at'],
        ]);
    }

    public function steps(string $organizationId,string $sequenceId):array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,step_id,sequence_id,touch_number,recommendation_id,parent_recommendation_id,created_by,created_at
             FROM tn_growth_engagement_sequence_steps
             WHERE organization_id=:organization_id AND sequence_id=:sequence_id
             ORDER BY touch_number'
        );
        $statement->execute(['organization_id'=>$organizationId,'sequence_id'=>$sequenceId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){
            $row['touch_number']=(int)$row['touch_number'];
            $row['created_by']=(int)$row['created_by'];
        }
        unset($row);
        return array_values($rows);
    }

    public function latestStep(string $organizationId,string $sequenceId):?array
    {
        $rows=$this->steps($organizationId,$sequenceId);
        return $rows===[]?null:$rows[count($rows)-1];
    }

    public function scheduleDue(string $organizationId,string $sequenceId,string $nextDueAt):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_sequences
             SET next_due_at=:next_due_at,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND sequence_id=:sequence_id AND status=\'active\''
        );
        $statement->execute([
            'next_due_at'=>$nextDueAt,'organization_id'=>$organizationId,'sequence_id'=>$sequenceId,
        ]);
    }

    public function advance(string $organizationId,string $sequenceId,int $touchCount,string $recommendationId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_sequences
             SET touch_count=:touch_count,last_recommendation_id=:recommendation_id,next_due_at=NULL,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND sequence_id=:sequence_id AND status=\'active\''
        );
        $statement->execute([
            'touch_count'=>$touchCount,'recommendation_id'=>$recommendationId,
            'organization_id'=>$organizationId,'sequence_id'=>$sequenceId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth outreach sequence could not advance.');
    }

    public function finish(string $organizationId,string $sequenceId,string $status,string $code,string $reason):void
    {
        if(!in_array($status,['stopped','completed'],true)){
            throw new InvalidArgumentException('Growth outreach sequence finish status is invalid.');
        }
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_sequences
             SET status=:status,stop_code=:stop_code,stop_reason=:stop_reason,next_due_at=NULL,
                 updated_at=NOW(6),finished_at=NOW(6)
             WHERE organization_id=:organization_id AND sequence_id=:sequence_id AND status=\'active\''
        );
        $statement->execute([
            'status'=>$status,'stop_code'=>$code,'stop_reason'=>$reason,
            'organization_id'=>$organizationId,'sequence_id'=>$sequenceId,
        ]);
    }

    private function sequenceRow(string $sql,array $params):?array
    {
        $row=$this->one($sql,$params);
        return $row===null?null:$this->hydrateSequence($row);
    }

    private function hydrateSequence(array $row):array
    {
        $row['touch_count']=(int)$row['touch_count'];
        $row['max_touches']=(int)$row['max_touches'];
        $row['started_by']=(int)$row['started_by'];
        return $row;
    }

    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function encode(array $value):string
    {
        return json_encode(array_values($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth sequence list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
