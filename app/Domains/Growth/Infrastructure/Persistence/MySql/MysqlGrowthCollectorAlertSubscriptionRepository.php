<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthCollectorAlertSubscriptionRepositoryInterface;
use Domains\Growth\Domain\GrowthCollectorAlertSubscription;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthCollectorAlertSubscriptionRepository implements GrowthCollectorAlertSubscriptionRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function create(GrowthCollectorAlertSubscription $subscription,int $actorId):void
    {
        $this->execute(
            'INSERT INTO tn_growth_collector_alert_subscriptions
             (organization_id,subscription_id,recipient_email,recipient_name,locale,enabled,created_by,updated_by)
             VALUES(:organization_id,:subscription_id,:recipient_email,:recipient_name,:locale,:enabled,:created_by,:updated_by)',
            [
                'organization_id'=>$subscription->organizationId->value(),'subscription_id'=>$subscription->id,
                'recipient_email'=>$subscription->recipientEmail,'recipient_name'=>$subscription->recipientName,
                'locale'=>$subscription->locale,'enabled'=>$subscription->enabled()?1:0,
                'created_by'=>$actorId,'updated_by'=>$actorId,
            ],
        );
    }

    public function lock(string $organizationId,string $subscriptionId):GrowthCollectorAlertSubscription
    {
        $row=$this->one(
            'SELECT organization_id,subscription_id,recipient_email,recipient_name,locale,enabled
             FROM tn_growth_collector_alert_subscriptions
             WHERE organization_id=:organization_id AND subscription_id=:subscription_id LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'subscription_id'=>$subscriptionId],
        );
        if($row===null)throw new InvalidArgumentException('Growth collector alert subscription was not found.');
        return $this->hydrate($row);
    }

    public function update(GrowthCollectorAlertSubscription $subscription,int $actorId):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_collector_alert_subscriptions
             SET enabled=:enabled,updated_by=:updated_by,updated_at=NOW(6)
             WHERE organization_id=:organization_id AND subscription_id=:subscription_id'
        );
        $statement->execute([
            'enabled'=>$subscription->enabled()?1:0,'updated_by'=>$actorId,
            'organization_id'=>$subscription->organizationId->value(),'subscription_id'=>$subscription->id,
        ]);
        if($statement->rowCount()>1)throw new InvalidArgumentException('Growth collector alert subscription update changed too many rows.');
    }

    public function view(string $organizationId,string $subscriptionId):?array
    {
        return $this->row(
            'SELECT organization_id,subscription_id,recipient_email,recipient_name,locale,enabled,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_collector_alert_subscriptions
             WHERE organization_id=:organization_id AND subscription_id=:subscription_id LIMIT 1',
            ['organization_id'=>$organizationId,'subscription_id'=>$subscriptionId],
        );
    }

    public function findByEmail(string $organizationId,string $recipientEmail):?array
    {
        return $this->row(
            'SELECT organization_id,subscription_id,recipient_email,recipient_name,locale,enabled,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_collector_alert_subscriptions
             WHERE organization_id=:organization_id AND recipient_email=:recipient_email LIMIT 1',
            ['organization_id'=>$organizationId,'recipient_email'=>mb_strtolower(trim($recipientEmail))],
        );
    }

    public function listAll(string $organizationId,int $limit=100):array{return $this->list($organizationId,false,$limit);}
    public function listEnabled(string $organizationId,int $limit=100):array{return $this->list($organizationId,true,$limit);}

    private function list(string $organizationId,bool $enabledOnly,int $limit):array
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Growth collector alert subscription limit is invalid.');
        $statement=$this->connection->prepare(
            'SELECT organization_id,subscription_id,recipient_email,recipient_name,locale,enabled,
                    created_by,updated_by,created_at,updated_at
             FROM tn_growth_collector_alert_subscriptions
             WHERE organization_id=:organization_id'.($enabledOnly?' AND enabled=1':'').'
             ORDER BY recipient_email LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        return array_values(array_map(fn(array $row):array=>$this->normalize($row),$statement->fetchAll(PDO::FETCH_ASSOC)?:[]));
    }

    private function hydrate(array $row):GrowthCollectorAlertSubscription
    {
        return new GrowthCollectorAlertSubscription(
            (string)$row['subscription_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['recipient_email'],$row['recipient_name']===null?null:(string)$row['recipient_name'],
            (string)$row['locale'],(bool)$row['enabled'],
        );
    }

    private function row(string $sql,array $params):?array
    {
        $row=$this->one($sql,$params);
        return $row===null?null:$this->normalize($row);
    }

    private function normalize(array $row):array
    {
        $row['enabled']=(bool)$row['enabled'];
        foreach(['created_by','updated_by'] as $field)if(isset($row[$field]))$row[$field]=(int)$row[$field];
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
}
