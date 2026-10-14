<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

/**
 * Bridges trusted reconstructed books to the existing raw ingestion contract.
 * The canonical normalizer is responsible for mapping event types and sources.
 */
final readonly class L2IngestionPublisher
{
    public function __construct(
        private MarketDataIngestionInterface $ingestion,
        private string $organizationId,
        private MarketSourceId $sourceId,
        private ?VenueId $venueId,
        private string $symbol,
        private string $eventType,
    ) {}

    public function publish(MarketOrderBook $book): MarketDataIngestionResult
    {
        $payload = $book->toArray();
        $event = new RawMarketEvent(
            'cm-l2-'.bin2hex(random_bytes(16)),
            $this->sourceId,
            $this->venueId,
            $this->symbol,
            $this->eventType,
            null,
            new DateTimeImmutable('now'),
            $book->sequence,
            ['book'=>$payload],
            ['transport'=>'WEBSOCKET','recovery'=>'VERIFIED','timestamp_authority'=>'RECEIVED_AT'],
            MarketDataMode::Live,
        );
        return $this->ingestion->ingest($this->organizationId, $event);
    }
}
