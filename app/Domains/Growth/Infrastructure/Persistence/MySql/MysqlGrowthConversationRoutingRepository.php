<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthConversationRoutingRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthConversationRoutingRepository implements GrowthConversationRoutingRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function routeForClassification(string $organizationId,string $classificationId):?array
    {
        return $this->one(
            'SELECT organization_id,route_id,response_id,classification_id,candidate_id,recommendation_id,contact_id,
                    route,policy_version,decision_reason,status,target_reference_type,target_reference_id,error_summary,
                    created_at,updated_at
             FROM tn_growth_conversation_routes
             WHERE organization_id=:organization_id AND classification_id=:classification_id LIMIT 1',
            ['organization_id'=>$organizationId,'classification_id'=>$classificationId],
        );
    }

    public function appendRoute(array $route):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_conversation_routes
             (organization_id,route_id,response_id,classification_id,candidate_id,recommendation_id,contact_id,
              route,policy_version,decision_reason,status,created_at,updated_at)
             VALUES(:organization_id,:route_id,:response_id,:classification_id,:candidate_id,:recommendation_id,:contact_id,
                    :route,:policy_version,:decision_reason,:status,:created_at,:updated_at)'
        );
        $statement->execute([
            'organization_id'=>$route['organization_id'],'route_id'=>$route['route_id'],'response_id'=>$route['response_id'],
            'classification_id'=>$route['classification_id'],'candidate_id'=>$route['candidate_id'],
            'recommendation_id'=>$route['recommendation_id'],'contact_id'=>$route['contact_id'],
            'route'=>$route['route'],'policy_version'=>$route['policy_version'],'decision_reason'=>$route['decision_reason'],
            'status'=>$route['status'],'created_at'=>$route['created_at'],'updated_at'=>$route['updated_at'],
        ]);
    }

    public function updateRouteStatus(
        string $organizationId,string $routeId,string $status,
        ?string $referenceType=null,?string $referenceId=null,?string $errorSummary=null
    ):void {
        if(!in_array($status,['pending','queued','completed','rejected','failed'],true)){
            throw new InvalidArgumentException('Growth conversation route status is invalid.');
        }
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_conversation_routes
             SET status=:status,target_reference_type=:target_reference_type,target_reference_id=:target_reference_id,
                 error_summary=:error_summary,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND route_id=:route_id'
        );
        $statement->execute([
            'status'=>$status,'target_reference_type'=>$referenceType,'target_reference_id'=>$referenceId,
            'error_summary'=>$errorSummary,'organization_id'=>$organizationId,'route_id'=>$routeId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth conversation route could not be updated.');
    }

    public function suppressContact(string $organizationId,string $contactId,string $sourceResponseId,string $reason):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_suppressions
             (organization_id,contact_id,source_response_id,reason,active,created_at,updated_at)
             VALUES(:organization_id,:contact_id,:source_response_id,:reason,1,NOW(6),NOW(6))
             ON DUPLICATE KEY UPDATE source_response_id=VALUES(source_response_id),reason=VALUES(reason),active=1,updated_at=NOW(6)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'contact_id'=>$contactId,
            'source_response_id'=>$sourceResponseId,'reason'=>$reason,
        ]);
    }

    public function isContactSuppressed(string $organizationId,string $contactId):bool
    {
        $statement=$this->connection->prepare(
            'SELECT 1 FROM tn_growth_engagement_suppressions
             WHERE organization_id=:organization_id AND contact_id=:contact_id AND active=1 LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'contact_id'=>$contactId]);
        return $statement->fetchColumn()!==false;
    }

    public function latestForCandidate(string $organizationId,string $candidateId,int $limit=20):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT organization_id,route_id,response_id,classification_id,candidate_id,recommendation_id,contact_id,
                    route,policy_version,decision_reason,status,target_reference_type,target_reference_id,error_summary,
                    created_at,updated_at
             FROM tn_growth_conversation_routes
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY created_at DESC,route_id DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);return $row===false?null:$row;
    }
}
