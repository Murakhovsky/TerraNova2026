<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketQualityMetricRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use PDO;

final readonly class MysqlMarketQualityMetricRepository implements MarketQualityMetricRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function append(
        string $organizationId,
        CanonicalMarketEvent $event,
        MarketDataQualityAssessment $assessment,
        DateTimeImmutable $recordedAt,
    ):void{
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_quality_metrics
             (organization_id,canonical_event_id,source_id,venue_id,instrument_id,trust_status,quality_score,flags_json,
              ingestion_latency_ms,processing_latency_ms,event_age_ms,reference_deviation_bps,recorded_at)
             VALUES
             (:organization_id,:canonical_event_id,:source_id,:venue_id,:instrument_id,:trust_status,:quality_score,:flags_json,
              :ingestion_latency_ms,:processing_latency_ms,:event_age_ms,:reference_deviation_bps,:recorded_at)
             ON DUPLICATE KEY UPDATE
              trust_status=VALUES(trust_status),quality_score=VALUES(quality_score),flags_json=VALUES(flags_json),
              ingestion_latency_ms=VALUES(ingestion_latency_ms),processing_latency_ms=VALUES(processing_latency_ms),
              event_age_ms=VALUES(event_age_ms),reference_deviation_bps=VALUES(reference_deviation_bps),recorded_at=VALUES(recorded_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'canonical_event_id'=>$event->eventId,'source_id'=>$event->sourceId->value(),
            'venue_id'=>$event->venueId?->value(),'instrument_id'=>$event->instrumentId->value(),
            'trust_status'=>$assessment->status->value,'quality_score'=>$assessment->score,
            'flags_json'=>json_encode(array_map(static fn($flag):string=>$flag->value,$assessment->flags),JSON_THROW_ON_ERROR),
            'ingestion_latency_ms'=>$assessment->ingestionLatencyMilliseconds,
            'processing_latency_ms'=>$assessment->processingLatencyMilliseconds,
            'event_age_ms'=>$assessment->eventAgeMilliseconds,
            'reference_deviation_bps'=>$assessment->referenceDeviationBps?->value(),
            'recorded_at'=>$recordedAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
