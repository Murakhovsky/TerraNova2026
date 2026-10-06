<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketRateLimitState;
use Domains\CapitalMarkets\Domain\MarketData\MarketSequencePolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use PDO;

final readonly class MysqlMarketSourceRepository implements MarketSourceRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function save(string $organizationId,MarketSourceDescriptor $source):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_data_sources
             (organization_id,source_id,venue_id,adapter_type,enabled,priority,roles_json,credentials_reference,
              rate_limit_policy_json,reconnect_policy_json,health_policy_json,quality_policy_json,license_profile,metadata_json)
             VALUES
             (:organization_id,:source_id,:venue_id,:adapter_type,:enabled,:priority,:roles_json,:credentials_reference,
              :rate_limit_policy_json,:reconnect_policy_json,:health_policy_json,:quality_policy_json,:license_profile,:metadata_json)
             ON DUPLICATE KEY UPDATE
              venue_id=VALUES(venue_id),adapter_type=VALUES(adapter_type),enabled=VALUES(enabled),priority=VALUES(priority),
              roles_json=VALUES(roles_json),credentials_reference=VALUES(credentials_reference),
              rate_limit_policy_json=VALUES(rate_limit_policy_json),reconnect_policy_json=VALUES(reconnect_policy_json),
              health_policy_json=VALUES(health_policy_json),quality_policy_json=VALUES(quality_policy_json),
              license_profile=VALUES(license_profile),metadata_json=VALUES(metadata_json)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'source_id'=>$source->id->value(),
            'venue_id'=>$source->venueId?->value(),
            'adapter_type'=>$source->adapterType,
            'enabled'=>$source->enabled?1:0,
            'priority'=>$source->priority,
            'roles_json'=>json_encode(array_map(static fn(MarketSourceRole $role):string=>$role->value,$source->roles),JSON_THROW_ON_ERROR),
            'credentials_reference'=>$source->credentialsReference,
            'rate_limit_policy_json'=>json_encode([
                'request_budget'=>$source->rateLimitPolicy->requestBudget,
                'window_seconds'=>$source->rateLimitPolicy->windowSeconds,
                'initial_backoff_ms'=>$source->rateLimitPolicy->initialBackoffMilliseconds,
                'maximum_backoff_ms'=>$source->rateLimitPolicy->maximumBackoffMilliseconds,
            ],JSON_THROW_ON_ERROR),
            'reconnect_policy_json'=>json_encode([
                'initial_delay_ms'=>$source->reconnectPolicy->initialDelayMilliseconds,
                'maximum_delay_ms'=>$source->reconnectPolicy->maximumDelayMilliseconds,
                'maximum_attempts'=>$source->reconnectPolicy->maximumAttempts,
            ],JSON_THROW_ON_ERROR),
            'health_policy_json'=>json_encode([
                'heartbeat_interval_ms'=>$source->healthPolicy->heartbeatIntervalMilliseconds,
                'heartbeat_timeout_ms'=>$source->healthPolicy->heartbeatTimeoutMilliseconds,
                'maximum_queue_lag'=>$source->healthPolicy->maximumQueueLag,
                'maximum_clock_drift_ms'=>$source->healthPolicy->maximumClockDriftMilliseconds,
            ],JSON_THROW_ON_ERROR),
            'quality_policy_json'=>$source->qualityPolicy===null?null:json_encode([
                'maximum_age_ms'=>$source->qualityPolicy->maximumAgeMillisecondsByType,
                'maximum_processing_latency_ms'=>$source->qualityPolicy->maximumProcessingLatencyMilliseconds,
                'maximum_clock_drift_ms'=>$source->qualityPolicy->maximumClockDriftMilliseconds,
                'maximum_spread_bps'=>$source->qualityPolicy->maximumSpreadBps,
                'maximum_jump_bps'=>$source->qualityPolicy->maximumJumpBps,
                'maximum_reference_deviation_bps'=>$source->qualityPolicy->maximumReferenceDeviationBps,
                'consecutive_sequence_required'=>$source->qualityPolicy->consecutiveSequenceRequired,
                'sequence_policy'=>array_map(static fn(MarketSequencePolicy $policy):string=>$policy->value,$source->qualityPolicy->sequencePolicyByType),
            ],JSON_THROW_ON_ERROR),
            'license_profile'=>$source->licenseProfile,
            'metadata_json'=>json_encode((object)$source->metadata,JSON_THROW_ON_ERROR),
        ]);
    }

    public function get(string $organizationId,MarketSourceId $id):?MarketSourceDescriptor
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_data_sources WHERE organization_id=:organization_id AND source_id=:source_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'source_id'=>$id->value()]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->hydrateSource($row):null;
    }

    public function list(string $organizationId,bool $enabledOnly=false):array
    {
        $sql='SELECT * FROM tn_capital_market_data_sources WHERE organization_id=:organization_id';
        if($enabledOnly)$sql.=' AND enabled=1';
        $sql.=' ORDER BY priority,source_id';
        $statement=$this->connection->prepare($sql);
        $statement->execute(['organization_id'=>$organizationId]);
        return array_map(fn(array $row):MarketSourceDescriptor=>$this->hydrateSource($row),$statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveHealth(string $organizationId,MarketSourceHealth $health):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_capital_market_source_health
             (organization_id,source_id,connection_state,last_event_at,last_heartbeat_at,failure_count,queue_lag,
              clock_reliable,last_error,messages_per_second,last_latency_ms,error_count,reconnect_count,rate_limit_state)
             VALUES
             (:organization_id,:source_id,:connection_state,:last_event_at,:last_heartbeat_at,:failure_count,:queue_lag,
              :clock_reliable,:last_error,:messages_per_second,:last_latency_ms,:error_count,:reconnect_count,:rate_limit_state)
             ON DUPLICATE KEY UPDATE
              connection_state=VALUES(connection_state),last_event_at=VALUES(last_event_at),last_heartbeat_at=VALUES(last_heartbeat_at),
              failure_count=VALUES(failure_count),queue_lag=VALUES(queue_lag),clock_reliable=VALUES(clock_reliable),
              last_error=VALUES(last_error),messages_per_second=VALUES(messages_per_second),last_latency_ms=VALUES(last_latency_ms),
              error_count=VALUES(error_count),reconnect_count=VALUES(reconnect_count),rate_limit_state=VALUES(rate_limit_state)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'source_id'=>$health->sourceId->value(),
            'connection_state'=>$health->connectionState->value,
            'last_event_at'=>$health->lastEventAt?->format('Y-m-d H:i:s.u'),
            'last_heartbeat_at'=>$health->lastHeartbeatAt?->format('Y-m-d H:i:s.u'),
            'failure_count'=>$health->failureCount,'queue_lag'=>$health->queueLag,'clock_reliable'=>$health->clockReliable?1:0,
            'last_error'=>$health->lastError,'messages_per_second'=>$health->messagesPerSecond?->value(),
            'last_latency_ms'=>$health->lastLatencyMilliseconds,'error_count'=>$health->errorCount,
            'reconnect_count'=>$health->reconnectCount,'rate_limit_state'=>$health->rateLimitState->value,
        ]);
    }

    public function health(string $organizationId,MarketSourceId $id):?MarketSourceHealth
    {
        $statement=$this->connection->prepare(
            'SELECT * FROM tn_capital_market_source_health WHERE organization_id=:organization_id AND source_id=:source_id LIMIT 1'
        );
        $statement->execute(['organization_id'=>$organizationId,'source_id'=>$id->value()]);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        return new MarketSourceHealth(
            MarketSourceId::fromString((string)$row['source_id']),
            MarketConnectionState::from((string)$row['connection_state']),
            $row['last_event_at']===null?null:new DateTimeImmutable((string)$row['last_event_at']),
            $row['last_heartbeat_at']===null?null:new DateTimeImmutable((string)$row['last_heartbeat_at']),
            (int)$row['failure_count'],(int)$row['queue_lag'],(bool)$row['clock_reliable'],
            $row['last_error']===null?null:(string)$row['last_error'],
            $row['messages_per_second']===null?null:Decimal::fromString((string)$row['messages_per_second']),
            $row['last_latency_ms']===null?null:(int)$row['last_latency_ms'],
            (int)$row['error_count'],(int)$row['reconnect_count'],MarketRateLimitState::from((string)$row['rate_limit_state']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateSource(array $row):MarketSourceDescriptor
    {
        $roles=json_decode((string)$row['roles_json'],true,flags:JSON_THROW_ON_ERROR);
        $rate=json_decode((string)$row['rate_limit_policy_json'],true,flags:JSON_THROW_ON_ERROR);
        $reconnect=json_decode((string)$row['reconnect_policy_json'],true,flags:JSON_THROW_ON_ERROR);
        $health=json_decode((string)$row['health_policy_json'],true,flags:JSON_THROW_ON_ERROR);
        $metadata=json_decode((string)$row['metadata_json'],true,flags:JSON_THROW_ON_ERROR);
        $quality=$row['quality_policy_json']===null?null:json_decode((string)$row['quality_policy_json'],true,flags:JSON_THROW_ON_ERROR);

        $sequence=[];
        if(is_array($quality['sequence_policy']??null)){
            foreach($quality['sequence_policy'] as $type=>$policy)$sequence[(string)$type]=MarketSequencePolicy::from((string)$policy);
        }

        $qualityPolicy=is_array($quality)?new MarketDataQualityPolicy(
            is_array($quality['maximum_age_ms']??null)?array_map(static fn(mixed $v):int=>(int)$v,$quality['maximum_age_ms']):[],
            (int)($quality['maximum_processing_latency_ms']??0),
            (int)($quality['maximum_clock_drift_ms']??0),
            (int)($quality['maximum_spread_bps']??1),
            (int)($quality['maximum_jump_bps']??1),
            (int)($quality['maximum_reference_deviation_bps']??1),
            (bool)($quality['consecutive_sequence_required']??false),
            $sequence,
        ):null;

        return new MarketSourceDescriptor(
            MarketSourceId::fromString((string)$row['source_id']),
            $row['venue_id']===null?null:VenueId::fromString((string)$row['venue_id']),
            (string)$row['adapter_type'],(bool)$row['enabled'],(int)$row['priority'],
            array_map(static fn(string $role):MarketSourceRole=>MarketSourceRole::from($role),is_array($roles)?$roles:[]),
            $row['credentials_reference']===null?null:(string)$row['credentials_reference'],
            new RateLimitPolicy(
                (int)$rate['request_budget'],(int)$rate['window_seconds'],
                (int)$rate['initial_backoff_ms'],(int)$rate['maximum_backoff_ms']
            ),
            new ReconnectPolicy(
                (int)$reconnect['initial_delay_ms'],(int)$reconnect['maximum_delay_ms'],(int)$reconnect['maximum_attempts']
            ),
            new MarketHealthPolicy(
                (int)$health['heartbeat_interval_ms'],(int)$health['heartbeat_timeout_ms'],
                (int)$health['maximum_queue_lag'],(int)$health['maximum_clock_drift_ms']
            ),
            is_array($metadata)?$metadata:[],
            (string)$row['license_profile'],
            $qualityPolicy,
        );
    }
}
