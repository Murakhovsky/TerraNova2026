<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketGapRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataGap;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketGapStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use PDO;

final readonly class MysqlMarketGapRepository implements MarketGapRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,MarketDataGap $gap):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_data_gaps
             (organization_id,gap_id,source_id,instrument_id,data_type,gap_from,gap_to,reason,status)
             VALUES
             (:organization_id,:gap_id,:source_id,:instrument_id,:data_type,:gap_from,:gap_to,:reason,:status)
             ON DUPLICATE KEY UPDATE gap_to=VALUES(gap_to),reason=VALUES(reason),status=VALUES(status)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'gap_id'=>$gap->id,'source_id'=>$gap->sourceId->value(),
            'instrument_id'=>$gap->instrumentId->value(),'data_type'=>$gap->dataType->value,
            'gap_from'=>$gap->from->format('Y-m-d H:i:s.u'),'gap_to'=>$gap->to->format('Y-m-d H:i:s.u'),
            'reason'=>$gap->reason,'status'=>$gap->status->value,
        ]);
    }

    public function list(string $organizationId,?MarketGapStatus $status=null,int $limit=200):array
    {
        $limit=max(1,min(1000,$limit));
        $sql='SELECT * FROM tn_capital_market_data_gaps WHERE organization_id=:organization_id';
        $params=['organization_id'=>$organizationId];
        if($status!==null){$sql.=' AND status=:status';$params['status']=$status->value;}
        $sql.=' ORDER BY gap_from DESC LIMIT '.$limit;
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        return array_map(static fn(array $row):MarketDataGap=>new MarketDataGap(
            (string)$row['gap_id'],MarketSourceId::fromString((string)$row['source_id']),
            InstrumentId::fromString((string)$row['instrument_id']),MarketEventType::from((string)$row['data_type']),
            new DateTimeImmutable((string)$row['gap_from']),new DateTimeImmutable((string)$row['gap_to']),
            (string)$row['reason'],MarketGapStatus::from((string)$row['status']),
        ),$statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
