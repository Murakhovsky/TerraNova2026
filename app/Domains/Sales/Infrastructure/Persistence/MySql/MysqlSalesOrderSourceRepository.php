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
