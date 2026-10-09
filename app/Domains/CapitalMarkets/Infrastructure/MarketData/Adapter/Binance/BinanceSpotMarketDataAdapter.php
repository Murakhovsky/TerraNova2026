<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketConnectionState, MarketDataBatch, MarketDataCapability, MarketDataInstrumentTarget,
    MarketSourceDescriptor, MarketSourceHealth, RawMarketEvent
};
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

/** Public, read-only Binance Spot adapter. Never authenticates or trades. */
final readonly class BinanceSpotMarketDataAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE = 'binance.spot.rest';
    private const HOST = 'data-api.binance.vision';
    private const BASE = 'https://data-api.binance.vision';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private BinanceSpotPayloadParser $parser,
        private VenueMarketStatusResolver $status,
    ) {}

    public function adapterType(): string { return self::ADAPTER_TYPE; }
    public function getSource(): string { return 'BINANCE'; }

    public function getCapabilities(): array
    {
        return [MarketDataCapability::Bbo, MarketDataCapability::Volume, MarketDataCapability::OrderBook];
    }

    public function supports(MarketDataCapability $capability, MarketDataInstrumentTarget $target): bool
    {
        return in_array($capability, $this->getCapabilities(), true) && $target->venueInstrument !== null;
    }

    public function resolveInstrument(
        string $organizationId, MarketSourceDescriptor $source, MarketDataInstrumentTarget $target,
    ): ?string {
        if ($source->adapterType !== self::ADAPTER_TYPE || $source->venueId === null
            || $target->venueInstrument === null || !$target->venueInstrument->venueId->equals($source->venueId)) {
            return null;
        }
        $symbol = strtoupper($target->externalSymbol);
        return preg_match('/^[A-Z0-9]{3,40}$/D', $symbol) === 1 ? $symbol : null;
    }

    public function getSnapshot(
        string $organizationId, MarketSourceDescriptor $source, MarketDataInstrumentTarget $target, array $capabilities,
    ): MarketDataBatch {
        $symbol = $this->resolveInstrument($organizationId, $source, $target);
        if ($symbol === null || $capabilities === []) {
            throw new InvalidArgumentException('Binance requires a mapped Spot symbol and requested capability.');
        }
        $requested = [];
        foreach ($capabilities as $capability) {
            if (!$capability instanceof MarketDataCapability || !$this->supports($capability, $target)) {
                throw new InvalidArgumentException('Unsupported Binance Spot market-data capability.');
            }
            $requested[$capability->value] = true;
        }
        $received = $this->clock->now();
        $marketStatus = $this->status->resolve($target->venueInstrument, $received)->value;
        $metadata = ['provider'=>'BINANCE','transport'=>'REST','market_status'=>$marketStatus];
        $events = [];

        if (isset($requested[MarketDataCapability::Bbo->value])) {
            $body = $this->fetch($organizationId, '/api/v3/ticker/bookTicker?symbol='.rawurlencode($symbol), 'bbo');
            $this->parser->bbo($body, $symbol);
            $events[] = new RawMarketEvent(
                $this->eventId('bbo'), $source->id, $source->venueId, $symbol,
                'binance.spot.ticker.bbo', null, $received, null,
                ['raw_json'=>$body], $metadata + ['timestamp_authority'=>'RECEIVED_AT'],
            );
        }
        if (isset($requested[MarketDataCapability::Volume->value])) {
            $body = $this->fetch($organizationId, '/api/v3/ticker/24hr?symbol='.rawurlencode($symbol), 'volume');
            $this->parser->volume($body, $symbol);
            $events[] = new RawMarketEvent(
                $this->eventId('volume'), $source->id, $source->venueId, $symbol,
                'binance.spot.ticker.volume', null, $received, null,
                ['raw_json'=>$body], $metadata + ['timestamp_authority'=>'RECEIVED_AT'],
            );
        }
        if (isset($requested[MarketDataCapability::OrderBook->value])) {
            $body = $this->fetch($organizationId, '/api/v3/depth?symbol='.rawurlencode($symbol).'&limit=50', 'depth');
            $depth = $this->parser->depth($body);
            $events[] = new RawMarketEvent(
                $this->eventId('book'), $source->id, $source->venueId, $symbol,
                'binance.spot.depth.snapshot', null, $received, (string) $depth['sequence'],
                ['raw_json'=>$body], $metadata + ['timestamp_authority'=>'RECEIVED_AT'],
            );
        }
        return new MarketDataBatch($source->id, $events);
    }

    public function getHealth(string $organizationId, MarketSourceDescriptor $source): MarketSourceHealth
    {
        return new MarketSourceHealth(
            $source->id,
            $source->enabled ? MarketConnectionState::Connected : MarketConnectionState::Disabled,
            null, null, 0, 0, $this->clock->reliable(),
        );
    }

    private function fetch(string $org, string $path, string $kind): string
    {
        return $this->http->get($org, 'capital_markets.binance.spot.'.$kind, self::BASE.$path, [], [self::HOST]);
    }

    private function eventId(string $kind): string
    {
        return 'cm-raw-binance-'.$kind.'-'.bin2hex(random_bytes(12));
    }
}
