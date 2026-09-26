<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthAutonomousOutreachRepository implements GrowthAutonomousOutreachRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function latestProfile(string $organizationId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,profile_id,revision,enabled,min_confidence,allowed_channels_json,allowed_statuses_json,max_actions_per_run,reason,created_by,created_at
             FROM tn_growth_engagement_autonomy_profiles
             WHERE organization_id=:organization_id ORDER BY revision DESC LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['revision']=(int)$row['revision'];
        $row['enabled']=(bool)$row['enabled'];
        $row['min_confidence']=(float)$row['min_confidence'];
        $row['allowed_channels']=$this->decodeList((string)$row['allowed_channels_json']);
        $row['allowed_statuses']=$this->decodeList((string)$row['allowed_statuses_json']);
        $row['max_actions_per_run']=(int)$row['max_actions_per_run'];
        $row['created_by']=(int)$row['created_by'];
        unset($row['allowed_channels_json'],$row['allowed_statuses_json']);
        return $row;
    }

    public function appendProfile(array $profile):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_autonomy_profiles
             (organization_id,profile_id,revision,enabled,min_confidence,allowed_channels_json,allowed_statuses_json,max_actions_per_run,reason,created_by,created_at)
             VALUES(:organization_id,:profile_id,:revision,:enabled,:min_confidence,:allowed_channels_json,:allowed_statuses_json,:max_actions_per_run,:reason,:created_by,:created_at)'
        );
        $statement->execute([
            'organization_id'=>$profile['organization_id'],'profile_id'=>$profile['profile_id'],'revision'=>$profile['revision'],
            'enabled'=>$profile['enabled']?1:0,'min_confidence'=>$profile['min_confidence'],
            'allowed_channels_json'=>$this->encode($profile['allowed_channels']),'allowed_statuses_json'=>$this->encode($profile['allowed_statuses']),
            'max_actions_per_run'=>$profile['max_actions_per_run'],'reason'=>$profile['reason'],
            'created_by'=>$profile['created_by'],'created_at'=>$profile['created_at'],
        ]);
    }

    public function enabledOrganizations(int $limit):array
    {
        $limit=max(1,min(1000,$limit));
        $sql='SELECT p.organization_id
              FROM tn_growth_engagement_autonomy_profiles p
              INNER JOIN (
                SELECT organization_id,MAX(revision) AS revision
                FROM tn_growth_engagement_autonomy_profiles GROUP BY organization_id
              ) latest ON latest.organization_id=p.organization_id AND latest.revision=p.revision
              WHERE p.enabled=1
              ORDER BY p.organization_id
              LIMIT '.$limit;
        return array_values(array_map('strval',$this->connection->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[]));
    }

    public function latestPayload(string $organizationId,string $recommendationId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,payload_id,recommendation_id,candidate_id,revision,body,payload_fingerprint,reason,staged_by,staged_at
             FROM tn_growth_engagement_autonomy_payloads
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
             ORDER BY revision DESC LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['revision']=(int)$row['revision'];
        $row['staged_by']=(int)$row['staged_by'];
        return $row;
    }

    public function appendPayload(array $payload):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_autonomy_payloads
             (organization_id,payload_id,recommendation_id,candidate_id,revision,body,payload_fingerprint,reason,staged_by,staged_at)
             VALUES(:organization_id,:payload_id,:recommendation_id,:candidate_id,:revision,:body,:payload_fingerprint,:reason,:staged_by,:staged_at)'
        );
        $statement->execute([
            'organization_id'=>$payload['organization_id'],'payload_id'=>$payload['payload_id'],
            'recommendation_id'=>$payload['recommendation_id'],'candidate_id'=>$payload['candidate_id'],
            'revision'=>$payload['revision'],'body'=>$payload['body'],'payload_fingerprint'=>$payload['payload_fingerprint'],
            'reason'=>$payload['reason'],'staged_by'=>$payload['staged_by'],'staged_at'=>$payload['staged_at'],
        ]);
    }

    public function pendingPayloads(string $organizationId,int $limit):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT p.recommendation_id,p.candidate_id,p.payload_id,p.revision,p.payload_fingerprint,p.staged_at
             FROM tn_growth_engagement_autonomy_payloads p
             INNER JOIN (
               SELECT organization_id,recommendation_id,MAX(revision) AS revision
               FROM tn_growth_engagement_autonomy_payloads
               GROUP BY organization_id,recommendation_id
             ) latest_payload ON latest_payload.organization_id=p.organization_id
                             AND latest_payload.recommendation_id=p.recommendation_id
                             AND latest_payload.revision=p.revision
             INNER JOIN tn_growth_engagement_recommendations r
               ON r.organization_id=p.organization_id AND r.recommendation_id=p.recommendation_id
             INNER JOIN (
               SELECT organization_id,MAX(revision) AS revision
               FROM tn_growth_engagement_autonomy_profiles
               GROUP BY organization_id
             ) latest_profile ON latest_profile.organization_id=p.organization_id
             INNER JOIN tn_growth_engagement_autonomy_profiles policy
               ON policy.organization_id=latest_profile.organization_id AND policy.revision=latest_profile.revision
             INNER JOIN (
               SELECT organization_id,MAX(revision) AS revision
               FROM tn_growth_engagement_activation_profiles
               GROUP BY organization_id
             ) latest_activation ON latest_activation.organization_id=p.organization_id
             INNER JOIN tn_growth_engagement_activation_profiles activation
               ON activation.organization_id=latest_activation.organization_id AND activation.revision=latest_activation.revision
             LEFT JOIN tn_growth_engagement_execution_links e
               ON e.organization_id=p.organization_id AND e.recommendation_id=p.recommendation_id
             WHERE p.organization_id=:organization_id
               AND policy.enabled=1
               AND r.confidence>=policy.min_confidence
               AND JSON_CONTAINS(policy.allowed_channels_json,JSON_QUOTE(r.channel))
               AND JSON_CONTAINS(policy.allowed_statuses_json,JSON_QUOTE(r.status))
               AND CASE r.channel
                     WHEN \'email\' THEN activation.email_mode
                     WHEN \'linkedin\' THEN activation.linkedin_mode
                     WHEN \'phone\' THEN activation.phone_mode
                     ELSE \'blocked\'
                   END=\'auto\'
               AND e.execution_id IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM tn_growth_engagement_sequence_steps ss
                 INNER JOIN tn_growth_engagement_sequences sq
                   ON sq.organization_id=ss.organization_id AND sq.sequence_id=ss.sequence_id
                 WHERE ss.organization_id=p.organization_id
                   AND ss.recommendation_id=p.recommendation_id AND sq.status<>\'active\'
               )
             ORDER BY p.staged_at,p.recommendation_id
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['revision']=(int)$row['revision'];
        unset($row);
        return array_values($rows);
    }

    /** @param list<string> $values */
    private function encode(array $values):string
    {
        return json_encode(array_values($values),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth autonomous outreach list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
