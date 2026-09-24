<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthEngagementDeliveryRepository implements GrowthEngagementDeliveryRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function recordOrVerify(array $observation):array
    {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_engagement_delivery_observations '
            .'(organization_id,observation_id,source_event_id,execution_id,candidate_id,recommendation_id,action_id,channel,status,terminal,'
            .'provider_reference,reason_code,reason_text,occurred_at,created_at) '
            .'VALUES(:organization_id,:observation_id,:source_event_id,:execution_id,:candidate_id,:recommendation_id,:action_id,:channel,:status,:terminal,'
            .':provider_reference,:reason_code,:reason_text,:occurred_at,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$observation['organization_id'],
            'observation_id'=>$observation['observation_id'],
            'source_event_id'=>$observation['source_event_id'],
            'execution_id'=>$observation['execution_id'],
            'candidate_id'=>$observation['candidate_id'],
            'recommendation_id'=>$observation['recommendation_id'],
            'action_id'=>$observation['action_id'],
            'channel'=>$observation['channel'],
            'status'=>$observation['status'],
            'terminal'=>!empty($observation['terminal'])?1:0,
            'provider_reference'=>$observation['provider_reference'],
            'reason_code'=>$observation['reason_code'],
            'reason_text'=>$observation['reason_text'],
            'occurred_at'=>(new \DateTimeImmutable((string)$observation['occurred_at']))->format('Y-m-d H:i:s.u'),
        ]);

        $stored=$this->bySourceEvent((string)$observation['organization_id'],(string)$observation['source_event_id'])
            ?? throw new InvalidArgumentException('Growth delivery observation could not be read back.');

        foreach([
            'execution_id','candidate_id','recommendation_id','action_id','channel','status',
            'provider_reference','reason_code','reason_text','occurred_at',
        ] as $field){
            $expected=$observation[$field]??null;
            $actual=$stored[$field]??null;
            if($field==='occurred_at'&&$expected!==null){
                $expected=(new \DateTimeImmutable((string)$expected))->format('Y-m-d H:i:s.u');
            }
            if((string)($actual??'')!==(string)($expected??'')){
                throw new InvalidArgumentException('Growth delivery source event conflicts with an existing observation.');
            }
        }

        $stored['replayed']=$statement->rowCount()===0;
        return $stored;
    }

    public function latestForExecution(string $organizationId,string $executionId):?array
    {
        $rows=$this->forExecution($organizationId,$executionId,1);
        return $rows[0]??null;
    }

    public function forExecution(string $organizationId,string $executionId,int $limit=20):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT organization_id,observation_id,source_event_id,execution_id,candidate_id,recommendation_id,action_id,channel,status,terminal,'
            .'provider_reference,reason_code,reason_text,occurred_at,created_at '
            .'FROM tn_growth_engagement_delivery_observations '
            .'WHERE organization_id=:organization_id AND execution_id=:execution_id '
            .'ORDER BY occurred_at DESC,observation_id DESC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'execution_id'=>$executionId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['terminal']=(bool)$row['terminal'];
        unset($row);
        return $rows;
    }

    private function bySourceEvent(string $organizationId,string $sourceEventId):?array
    {
        $statement=$this->connection->prepare(
            'SELECT organization_id,observation_id,source_event_id,execution_id,candidate_id,recommendation_id,action_id,channel,status,terminal,'
            .'provider_reference,reason_code,reason_text,occurred_at,created_at '
            .'FROM tn_growth_engagement_delivery_observations '
            .'WHERE organization_id=:organization_id AND source_event_id=:source_event_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'source_event_id'=>$sourceEventId]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        $row['terminal']=(bool)$row['terminal'];
        return $row;
    }
}
