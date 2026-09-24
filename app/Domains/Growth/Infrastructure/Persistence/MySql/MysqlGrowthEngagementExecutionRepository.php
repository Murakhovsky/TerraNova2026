<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthEngagementExecutionRepository implements GrowthEngagementExecutionRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function byRecommendation(string $organizationId,string $recommendationId):?array
    {
        return $this->one(
            'SELECT organization_id,execution_id,candidate_id,recommendation_id,target_domain,target_reference_type,
                    target_reference_id,action_id,action_type,channel,payload_fingerprint,created_by,created_at
             FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
    }

    public function byActionId(string $organizationId,string $actionId):?array
    {
        return $this->one(
            'SELECT organization_id,execution_id,candidate_id,recommendation_id,target_domain,target_reference_type,
                    target_reference_id,action_id,action_type,channel,payload_fingerprint,created_by,created_at
             FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND action_id=:action_id LIMIT 1',
            ['organization_id'=>$organizationId,'action_id'=>$actionId],
        );
    }

    public function createOrVerify(
        string $organizationId,string $executionId,string $candidateId,string $recommendationId,
        string $targetDomain,string $targetReferenceType,string $targetReferenceId,string $actionId,
        string $actionType,string $channel,string $payloadFingerprint,int $actorId
    ):void {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_engagement_execution_links
             (organization_id,execution_id,candidate_id,recommendation_id,target_domain,target_reference_type,
              target_reference_id,action_id,action_type,channel,payload_fingerprint,created_by,created_at)
             VALUES(:organization_id,:execution_id,:candidate_id,:recommendation_id,:target_domain,:target_reference_type,
                    :target_reference_id,:action_id,:action_type,:channel,:payload_fingerprint,:created_by,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'execution_id'=>$executionId,'candidate_id'=>$candidateId,
            'recommendation_id'=>$recommendationId,'target_domain'=>$targetDomain,
            'target_reference_type'=>$targetReferenceType,'target_reference_id'=>$targetReferenceId,
            'action_id'=>$actionId,'action_type'=>$actionType,'channel'=>$channel,
            'payload_fingerprint'=>$payloadFingerprint,'created_by'=>$actorId,
        ]);

        $stored=$this->byRecommendation($organizationId,$recommendationId)
            ?? throw new InvalidArgumentException('Growth engagement execution link could not be read back.');
        foreach([
            'execution_id'=>$executionId,'candidate_id'=>$candidateId,'target_domain'=>$targetDomain,
            'target_reference_type'=>$targetReferenceType,'target_reference_id'=>$targetReferenceId,
            'action_id'=>$actionId,'action_type'=>$actionType,'channel'=>$channel,'payload_fingerprint'=>$payloadFingerprint,
        ] as $field=>$expected){
            if((string)($stored[$field]??'')!==$expected){
                throw new InvalidArgumentException('Growth engagement recommendation is already linked to another execution payload.');
            }
        }
    }

    public function latestForCandidate(string $organizationId,string $candidateId):?array
    {
        return $this->one(
            'SELECT organization_id,execution_id,candidate_id,recommendation_id,target_domain,target_reference_type,
                    target_reference_id,action_id,action_type,channel,payload_fingerprint,created_by,created_at
             FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY created_at DESC,execution_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
    }

    public function countPreHandoffSince(string $organizationId,string $since):int
    {
        $statement=$this->connection->prepare(
            "SELECT COUNT(*) FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND target_domain='growth' AND created_at>=:since"
        );
        $statement->execute(['organization_id'=>$organizationId,'since'=>$since]);
        return (int)$statement->fetchColumn();
    }

    public function countPreHandoffSinceByChannel(string $organizationId,string $channel,string $since):int
    {
        $statement=$this->connection->prepare(
            "SELECT COUNT(*) FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND target_domain='growth'
               AND channel=:channel AND created_at>=:since"
        );
        $statement->execute(['organization_id'=>$organizationId,'channel'=>$channel,'since'=>$since]);
        return (int)$statement->fetchColumn();
    }

    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array
    {
        return $this->one(
            "SELECT organization_id,execution_id,candidate_id,recommendation_id,target_domain,target_reference_type,
                    target_reference_id,action_id,action_type,channel,payload_fingerprint,created_by,created_at
             FROM tn_growth_engagement_execution_links
             WHERE organization_id=:organization_id AND target_domain='growth'
               AND target_reference_type='growth_contact' AND target_reference_id=:target_reference_id
             ORDER BY created_at DESC,execution_id DESC LIMIT 1",
            ['organization_id'=>$organizationId,'target_reference_id'=>$targetReferenceId],
        );
    }

    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }
}
