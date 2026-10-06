<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use PDO;

final readonly class MysqlMarketSubscriptionRepository implements MarketSubscriptionRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,MarketSubscription $subscription):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_subscriptions
             (organization_id,subscription_id,source_id,venue_id,instrument_id,data_type,status,subscribed_at,last_event_at)
             VALUES
             (:organization_id,:subscription_id,:source_id,:venue_id,:instrument_id,:data_type,:status,:subscribed_at,:last_event_at)
             ON DUPLICATE KEY UPDATE status=VALUES(status),subscribed_at=VALUES(subscribed_at),last_event_at=VALUES(last_event_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'subscription_id'=>$subscription->id->value(),
            'source_id'=>$subscription->sourceId->value(),'venue_id'=>$subscription->venueId?->value(),
            'instrument_id'=>$subscription->instrumentId->value(),'data_type'=>$subscription->dataType->value,
            'status'=>$subscription->status->value,'subscribed_at'=>$subscription->subscribedAt->format('Y-m-d H:i:s.u'),
            'last_event_at'=>$subscription->lastEventAt?->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function forSource(string $organizationId,MarketSourceId $sourceId,bool $activeOnly=false):array
    {
        $sql='SELECT * FROM tn_capital_market_subscriptions WHERE organization_id=:organization_id AND source_id=:source_id';
        if($activeOnly)$sql.=" AND status='ACTIVE'";
        $sql.=' ORDER BY subscription_id';
        $statement=$this->connection->prepare($sql);
        $statement->execute(['organization_id'=>$organizationId,'source_id'=>$sourceId->value()]);
        return array_map(static fn(array $row):MarketSubscription=>new MarketSubscription(
            MarketSubscriptionId::fromString((string)$row['subscription_id']),
            MarketSourceId::fromString((string)$row['source_id']),
            $row['venue_id']===null?null:VenueId::fromString((string)$row['venue_id']),
            InstrumentId::fromString((string)$row['instrument_id']),
            MarketEventType::from((string)$row['data_type']),
            MarketSubscriptionStatus::from((string)$row['status']),
            new DateTimeImmutable((string)$row['subscribed_at']),
            $row['last_event_at']===null?null:new DateTimeImmutable((string)$row['last_event_at']),
        ),$statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
