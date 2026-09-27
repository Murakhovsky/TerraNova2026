<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthEngagementResponseRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthEngagementResponseRepository implements GrowthEngagementResponseRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function recordOrVerify(array $response):array
    {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_engagement_responses
             (organization_id,response_id,source_event_id,execution_id,candidate_id,recommendation_id,action_id,channel,
              body,body_hash,provider_reference,thread_reference,occurred_at,created_at)
             VALUES(:organization_id,:response_id,:source_event_id,:execution_id,:candidate_id,:recommendation_id,:action_id,:channel,
                    :body,:body_hash,:provider_reference,:thread_reference,:occurred_at,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$response['organization_id'],'response_id'=>$response['response_id'],
            'source_event_id'=>$response['source_event_id'],'execution_id'=>$response['execution_id'],
            'candidate_id'=>$response['candidate_id'],'recommendation_id'=>$response['recommendation_id'],
            'action_id'=>$response['action_id'],'channel'=>$response['channel'],'body'=>$response['body'],
            'body_hash'=>$response['body_hash'],'provider_reference'=>$response['provider_reference'],
            'thread_reference'=>$response['thread_reference'],
            'occurred_at'=>(new \DateTimeImmutable((string)$response['occurred_at']))->format('Y-m-d H:i:s.u'),
        ]);

        $stored=$this->one(
            'SELECT * FROM tn_growth_engagement_responses
             WHERE organization_id=:organization_id AND source_event_id=:source_event_id LIMIT 1',
            ['organization_id'=>$response['organization_id'],'source_event_id'=>$response['source_event_id']],
        )??throw new InvalidArgumentException('Growth response could not be persisted.');

        foreach([
            'execution_id','candidate_id','recommendation_id','action_id','channel','body_hash',
            'provider_reference','thread_reference','occurred_at',
        ] as $field){
            $expected=$response[$field]??null;
            if($field==='occurred_at'&&$expected!==null){
                $expected=(new \DateTimeImmutable((string)$expected))->format('Y-m-d H:i:s.u');
            }
            $actual=$stored[$field]??null;
            if((string)($actual??'')!==(string)($expected??'')){
                throw new InvalidArgumentException('Growth response source event conflicts with an existing observation.');
            }
        }

        return $this->hydrateResponse($stored)+['replayed'=>$statement->rowCount()===0];
    }

    public function byId(string $organizationId,string $responseId):?array
    {
        $row=$this->one(
            'SELECT * FROM tn_growth_engagement_responses
             WHERE organization_id=:organization_id AND response_id=:response_id LIMIT 1',
            ['organization_id'=>$organizationId,'response_id'=>$responseId],
        );
        return $row===null?null:$this->hydrateResponse($row);
    }

    public function lockById(string $organizationId,string $responseId):array
    {
        $row=$this->one(
            'SELECT * FROM tn_growth_engagement_responses
             WHERE organization_id=:organization_id AND response_id=:response_id LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'response_id'=>$responseId],
        );
        return $row===null
            ?throw new InvalidArgumentException('Growth engagement response was not found.')
            :$this->hydrateResponse($row);
    }

    public function latestForCandidate(string $organizationId,string $candidateId,int $limit=20):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_growth_engagement_responses
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY occurred_at DESC,response_id DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        return array_map(fn(array $row):array=>$this->hydrateResponse($row),$statement->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }

    public function latestClassification(string $organizationId,string $responseId):?array
    {
        $row=$this->one(
            'SELECT * FROM tn_growth_engagement_response_classifications
             WHERE organization_id=:organization_id AND response_id=:response_id
             ORDER BY revision DESC LIMIT 1',
            ['organization_id'=>$organizationId,'response_id'=>$responseId],
        );
        return $row===null?null:$this->hydrateClassification($row);
    }

    public function classificationForVersion(string $organizationId,string $responseId,string $promptVersion,string $schemaVersion):?array
    {
        $row=$this->one(
            'SELECT * FROM tn_growth_engagement_response_classifications
             WHERE organization_id=:organization_id AND response_id=:response_id
               AND prompt_version=:prompt_version AND schema_version=:schema_version LIMIT 1',
            [
                'organization_id'=>$organizationId,'response_id'=>$responseId,
                'prompt_version'=>$promptVersion,'schema_version'=>$schemaVersion,
            ],
        );
        return $row===null?null:$this->hydrateClassification($row);
    }

    public function appendClassification(array $classification):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_response_classifications
             (organization_id,classification_id,response_id,revision,intent,sentiment,urgency,summary,requested_action,
              recommended_next_owner,confidence,provider,model,prompt_version,schema_version,input_tokens,output_tokens,
              cost_amount,cost_currency,created_at)
             VALUES(:organization_id,:classification_id,:response_id,:revision,:intent,:sentiment,:urgency,:summary,:requested_action,
                    :recommended_next_owner,:confidence,:provider,:model,:prompt_version,:schema_version,:input_tokens,:output_tokens,
                    :cost_amount,:cost_currency,:created_at)'
        );
        $statement->execute($classification);
    }

    private function hydrateResponse(array $row):array
    {
        return $row;
    }

    private function hydrateClassification(array $row):array
    {
        $row['revision']=(int)$row['revision'];
        $row['confidence']=(float)$row['confidence'];
        foreach(['input_tokens','output_tokens'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        return $row;
    }

    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }
}
