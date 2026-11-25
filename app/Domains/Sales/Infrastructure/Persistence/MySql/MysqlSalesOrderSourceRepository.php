<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Sales\Application\Contract\SalesOrderSourceRepositoryInterface;
use PDO;
use LogicException;

final readonly class MysqlSalesOrderSourceRepository implements SalesOrderSourceRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function lock(string $org,string $channel,string $connection,string $externalId):array
    {
        $this->transaction();
        $source=hash('sha256',json_encode([$org,$channel,$connection,$externalId],JSON_THROW_ON_ERROR));
        $q=$this->connection->prepare('INSERT INTO sales_order_sources(organization_id,source_id,channel,store_reference,external_order_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE source_id=source_id');
        $q->execute([$org,$source,$channel,$connection,$externalId]);
        $q=$this->connection->prepare('SELECT * FROM sales_order_sources WHERE organization_id=? AND source_id=? FOR UPDATE');
        $q->execute([$org,$source]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||$row['channel']!==$channel||$row['store_reference']!==$connection||$row['external_order_id']!==$externalId)throw new LogicException('Commerce source identity conflict.');
        return $row;
    }
    /** Redacted provider facts, not local fulfillment or payment authority. */
    public function latestForOrder(string $org,string $orderId):?array
    {
        $q=$this->connection->prepare('SELECT s.source_id,s.outcome,s.blocked_reason,o.observation FROM sales_order_sources s JOIN sales_order_source_observations o ON o.organization_id=s.organization_id AND o.source_id=s.source_id AND o.provider_updated_at=s.latest_updated_at AND o.provider_hash=s.latest_hash WHERE s.organization_id=? AND s.order_id=? LIMIT 2');
        $q->execute([$org,$orderId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return null;
        if(count($rows)!==1)throw new LogicException('Ambiguous Order source observation.');
        $row=$rows[0];$fact=json_decode($row['observation'],true,32,JSON_THROW_ON_ERROR);
        return ['source_id'=>$row['source_id'],'provider_updated_at'=>$fact['updated_at'],'provider_fulfillment_status'=>$fact['fulfillment_status'],
            'provider_financial_status'=>$fact['financial_status'],'provider_cancelled_at'=>$fact['cancelled_at'],
            'reconciliation_outcome'=>$row['outcome'],'review_reason'=>$row['blocked_reason']];
    }
    public function observe(string $org,string $sourceId,string $updated,string $hash,array $observation,string $outcome,?string $reason,?string $orderId,bool $advance):void
    {
        $this->transaction();
        $q=$this->connection->prepare('INSERT INTO sales_order_source_observations(organization_id,source_id,provider_updated_at,provider_hash,observation,outcome,blocked_reason) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_hash=provider_hash');
        $q->execute([$org,$sourceId,$updated,$hash,json_encode($observation,JSON_THROW_ON_ERROR),$outcome,$reason]);
        if(!$advance)return;
        $q=$this->connection->prepare('UPDATE sales_order_sources SET latest_updated_at=?,latest_hash=?,outcome=?,blocked_reason=?,order_id=COALESCE(order_id,?) WHERE organization_id=? AND source_id=?');
        $q->execute([$updated,$hash,$outcome,$reason,$orderId,$org,$sourceId]);
    }
    private function transaction():void
    {
        if(!$this->connection->inTransaction())throw new LogicException('Commerce observation requires transaction.');
    }
}
