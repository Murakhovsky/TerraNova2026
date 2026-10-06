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

    /** @return list<MarketSnapshot> */
    public function listRange(string $organizationId,\DateTimeImmutable $from,\DateTimeImmutable $to,int $limit=10000):array
    {
        if($to<$from)throw new \InvalidArgumentException('Snapshot range end must be on or after start.');
        $limit=max(1,min(10000,$limit));
        $statement=$this->connection->prepare(
            'SELECT payload_json FROM tn_capital_market_snapshots
             WHERE organization_id=:organization_id
               AND created_at>=:from_at
               AND created_at<=:to_at
             ORDER BY created_at ASC,snapshot_id ASC
             LIMIT '.$limit
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'from_at'=>$from->format('Y-m-d H:i:s.u'),
            'to_at'=>$to->format('Y-m-d H:i:s.u'),
        ]);
        $snapshots=[];
        while(($json=$statement->fetchColumn())!==false){
            if(!is_string($json))continue;
            $data=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
            if(is_array($data)&&!array_is_list($data))$snapshots[]=$this->hydrator->snapshot($data);
        }
        return $snapshots;
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
