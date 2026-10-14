<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Domain\Service\KrakenL2BookRecovery;

/**
 * Strict wire-frame decoder. Network clients own TLS, reconnect and backoff.
 * A separate router instance is required for each venue/symbol/subscription.
 */
final class L2WebSocketFrameRouter
{
    public function __construct(private readonly string $venue, private readonly string $symbol) {}

    /** @return array<string,mixed> */
    public function decode(string $json): array
    {
        $frame = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($frame) || array_is_list($frame)) throw new DomainException('Invalid L2 frame.');
        return match ($this->venue) {
            'BINANCE_SPOT', 'BINANCE_FUTURES' => $this->binance($frame),
            'BYBIT_SPOT', 'BYBIT_LINEAR' => $this->bybit($frame),
            'KRAKEN_SPOT' => $this->kraken($frame),
            default => throw new DomainException('Unsupported L2 venue.'),
        };
    }

    /** @param array<string,mixed> $frame @return array<string,mixed> */
    private function binance(array $frame): array
    {
        $row = $frame['data'] ?? $frame;
        if (!is_array($row) || ($row['e'] ?? null) !== 'depthUpdate' || ($row['s'] ?? null) !== $this->symbol) {
            throw new DomainException('Binance L2 symbol/event mismatch.');
        }
        $result = [
            'type' => 'delta', 'U' => $this->uint($row['U'] ?? null),
            'u' => $this->uint($row['u'] ?? null),
            'bids' => $this->pairs($row['b'] ?? null),
            'asks' => $this->pairs($row['a'] ?? null),
        ];
        if ($this->venue === 'BINANCE_FUTURES') $result['pu'] = $this->uint($row['pu'] ?? null);
        return $result;
    }

    /** @param array<string,mixed> $frame @return array<string,mixed> */
    private function bybit(array $frame): array
    {
        if (($frame['topic'] ?? null) === null || !preg_match('/^orderbook\.[0-9]+\./', (string) $frame['topic'])) {
            throw new DomainException('Bybit L2 topic mismatch.');
        }
        $row = $frame['data'] ?? null;
        if (!is_array($row) || ($row['s'] ?? null) !== $this->symbol) {
            throw new DomainException('Bybit L2 symbol mismatch.');
        }
        $type = $frame['type'] ?? null;
        if ($type !== 'snapshot' && $type !== 'delta') throw new DomainException('Bybit L2 event type mismatch.');
        return [
            'type' => $type, 'u' => $this->uint($row['u'] ?? null),
            'seq' => $this->uint($row['seq'] ?? null),
            'bids' => $this->pairs($row['b'] ?? null),
            'asks' => $this->pairs($row['a'] ?? null),
        ];
    }

    /** @param array<string,mixed> $frame @return array<string,mixed> */
    private function kraken(array $frame): array
    {
        if (($frame['channel'] ?? null) !== 'book' || !in_array($frame['type'] ?? null, ['snapshot', 'update'], true)) {
            throw new DomainException('Kraken L2 channel/event mismatch.');
        }
        $rows = $frame['data'] ?? null;
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0]) || ($rows[0]['symbol'] ?? null) !== $this->symbol) {
            throw new DomainException('Kraken L2 symbol mismatch.');
        }
        $row = $rows[0];
        return [
            'type' => $frame['type'] === 'snapshot' ? 'snapshot' : 'delta',
            'checksum' => $this->uint($row['checksum'] ?? null),
            'bids' => $this->levels($row['bids'] ?? null),
            'asks' => $this->levels($row['asks'] ?? null),
        ];
    }

    /** @return list<array{price:string,qty:string}> */
    private function pairs(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) throw new DomainException('L2 levels must be a list.');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 2) throw new DomainException('Invalid L2 price/quantity pair.');
            $out[] = ['price' => $this->decimal($row[0] ?? null), 'qty' => $this->decimal($row[1] ?? null)];
        }
        return $out;
    }

    /** @return list<array{price:string,qty:string}> */
    private function levels(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) throw new DomainException('Kraken L2 levels must be a list.');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new DomainException('Invalid Kraken L2 level.');
            $out[] = ['price' => $this->decimal($row['price'] ?? null), 'qty' => $this->decimal($row['qty'] ?? null)];
        }
        return $out;
    }

    private function decimal(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new DomainException('Invalid L2 decimal.');
        }
        return $value;
    }

    private function uint(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value)) {
            throw new DomainException('Invalid L2 unsigned ID.');
        }
        return (string) $value;
    }
}
