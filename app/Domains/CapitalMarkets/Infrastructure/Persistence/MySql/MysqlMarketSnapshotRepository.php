<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use PDO;

final readonly class MysqlMarketSnapshotRepository implements MarketSnapshotRepositoryInterface
{
    public function __construct(private PDO $connection,private MarketDataHydrator $hydrator){}

    public function save(string $organizationId,MarketSnapshot $snapshot):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_snapshots (organization_id,snapshot_id,created_at,payload_json)
             VALUES (:organization_id,:snapshot_id,:created_at,:payload_json)
             ON DUPLICATE KEY UPDATE created_at=VALUES(created_at),payload_json=VALUES(payload_json)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'snapshot_id'=>$snapshot->snapshotId,
            'created_at'=>$snapshot->createdAt->format('Y-m-d H:i:s.u'),
            'payload_json'=>json_encode($snapshot->toArray(),JSON_THROW_ON_ERROR),
        ]);
    }

    public function list(string $organizationId,int $limit=500):array
    {
        $limit=max(1,min(5000,$limit));
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_snapshots
             WHERE organization_id=:organization_id ORDER BY created_at ASC,snapshot_id ASC LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $out=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){
            $data=json_decode((string)$row['payload_json'],true,flags:JSON_THROW_ON_ERROR);
            if(is_array($data)&&!array_is_list($data))$out[]=$this->hydrator->snapshot($data);
        }
        return $out;
    }

    public function get(string $organizationId,string $snapshotId):?MarketSnapshot
    {
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_snapshots
             WHERE organization_id=:organization_id AND snapshot_id=:snapshot_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'snapshot_id'=>$snapshotId]);
        $json=$statement->fetchColumn();
        if(!is_string($json))return null;
        $data=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        return is_array($data)&&!array_is_list($data)?$this->hydrator->snapshot($data):null;
    }
}
