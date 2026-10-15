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
        private string $eventType = 'l2.verified.snapshot',
        private ?\Closure $fencedWriter = null,
    ) {
        if ($eventType !== 'l2.verified.snapshot') throw new \DomainException('Unsupported verified L2 event type.');
    }

    public function publish(MarketOrderBook $book): MarketDataIngestionResult
    {
        if ($book->type !== \Domains\CapitalMarkets\Domain\MarketData\MarketEventType::OrderBookSnapshot) throw new \DomainException('Verified ingestion requires a full book snapshot.');
        if ($book->sequence === null || $book->sequence === '') {
            throw new \DomainException('Verified L2 publication requires a sequence.');
        }
        $payload = [
            'bids' => array_map(static fn($level):array => ['price'=>$level->price->value->value(),'quantity'=>$level->quantity->value->value()],$book->bids),
            'asks' => array_map(static fn($level):array => ['price'=>$level->price->value->value(),'quantity'=>$level->quantity->value->value()],$book->asks),
        ];
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
        return $this->fencedWriter !== null
            ? ($this->fencedWriter)($event)
            : $this->ingestion->ingest($this->organizationId, $event);
    }
}
