<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketEventType, MarketSession, MarketSourceDescriptor, MarketStatus, RawMarketEvent, ReferenceType
};
use InvalidArgumentException;

final readonly class BinanceMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private BinanceSpotPayloadParser $parser) {}

    public function adapterType(): string { return BinanceSpotMarketDataAdapter::ADAPTER_TYPE; }

    public function decode(MarketSourceDescriptor $source, RawMarketEvent $event): DecodedMarketEvent
    {
        if ($source->adapterType !== $this->adapterType()) {
            throw new InvalidArgumentException('Binance decoder source mismatch.');
        }
        $body = $event->rawPayload['raw_json'] ?? null;
        if (!is_string($body) || $body === '') {
            throw new InvalidArgumentException('Missing Binance raw market evidence.');
        }
        $status = match ((string)($event->transportMetadata['market_status'] ?? 'UNKNOWN')) {
            'OPEN' => MarketStatus::Open, 'CLOSED' => MarketStatus::Closed, default => MarketStatus::Unknown,
        };
        $time = $event->receivedAt; // Binance Spot BBO has no provider timestamp: don't invent one.
        return match ($event->eventType) {
            'binance.spot.ticker.bbo' => new DecodedMarketEvent(
                MarketEventType::Bbo, $event->externalInstrument, $time, $event->sequence,
                $this->parser->bbo($body, $event->externalInstrument),
                $event->mode, $status, MarketSession::Unknown, ReferenceType::ProviderReference,
            ),
            'binance.spot.ticker.volume' => new DecodedMarketEvent(
                MarketEventType::Volume, $event->externalInstrument, $time, $event->sequence,
                $this->parser->volume($body, $event->externalInstrument),
                $event->mode, $status, MarketSession::Unknown, ReferenceType::ProviderReference,
            ),
            'binance.spot.depth.snapshot' => new DecodedMarketEvent(
                MarketEventType::OrderBookSnapshot, $event->externalInstrument, $time, $event->sequence,
                (static function (array $d): array { return ['bids'=>$d['bids'],'asks'=>$d['asks']]; })($this->parser->depth($body)),
                $event->mode, $status, MarketSession::Unknown, ReferenceType::ProviderReference,
            ),
            default => throw new InvalidArgumentException('Unsupported Binance market event type.'),
        };
    }
}
