<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance;

use InvalidArgumentException;
use JsonException;

/**
 * Fail-closed parser for Binance Spot public market-data responses.
 * All prices/quantities stay decimal strings; never infer a symbol or price.
 */
final class BinanceSpotPayloadParser
{
    /** @return array{bid_price:string,bid_quantity:string,ask_price:string,ask_quantity:string} */
    public function bbo(string $json, string $expectedSymbol): array
    {
        $row = $this->object($json);
        $this->assertSymbol($row, $expectedSymbol);
        $bid = $this->decimal($row['bidPrice'] ?? null, 'bidPrice');
        $ask = $this->decimal($row['askPrice'] ?? null, 'askPrice');
        return [
            'bid_price' => $bid,
            'bid_quantity' => $this->decimal($row['bidQty'] ?? null, 'bidQty', true),
            'ask_price' => $ask,
            'ask_quantity' => $this->decimal($row['askQty'] ?? null, 'askQty', true),
        ];
    }

    /** @return array{value:string} */
    public function volume(string $json, string $expectedSymbol): array
    {
        $row = $this->object($json);
        $this->assertSymbol($row, $expectedSymbol);
        return ['value' => $this->decimal($row['volume'] ?? null, 'volume', true)];
    }

    /** @return array{bids:list<array{price:string,quantity:string}>,asks:list<array{price:string,quantity:string}>,sequence:int} */
    public function depth(string $json): array
    {
        $row = $this->object($json);
        $sequence = $row['lastUpdateId'] ?? null;
        if ((!is_int($sequence) && !is_string($sequence)) || !ctype_digit((string) $sequence)
            || (int) $sequence < 1) {
            throw new InvalidArgumentException('Binance depth sequence is missing.');
        }
        $out = [];
        foreach (['bids', 'asks'] as $side) {
            $levels = $row[$side] ?? null;
            if (!is_array($levels) || !array_is_list($levels) || count($levels) > 500) {
                throw new InvalidArgumentException('Binance depth side invalid: '.$side);
            }
            $out[$side] = [];
            foreach ($levels as $level) {
                if (!is_array($level) || !array_is_list($level) || count($level) < 2) {
                    throw new InvalidArgumentException('Binance depth level invalid.');
                }
                $out[$side][] = [
                    'price' => $this->decimal($level[0] ?? null, 'price'),
                    'quantity' => $this->decimal($level[1] ?? null, 'quantity'),
                ];
            }
        }
        return ['bids' => $out['bids'], 'asks' => $out['asks'], 'sequence' => (int) $sequence];
    }

    /** @return array<string,mixed> */
    private function object(string $json): array
    {
        try {
            $row = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new InvalidArgumentException('Binance market response is not valid JSON.');
        }
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('Binance market response must be an object.');
        }
        if (isset($row['code']) || isset($row['msg'])) {
            throw new InvalidArgumentException('Binance returned an API error.');
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function assertSymbol(array $row, string $expectedSymbol): void
    {
        if (($row['symbol'] ?? null) !== $expectedSymbol) {
            throw new InvalidArgumentException('Binance quote symbol mismatch.');
        }
    }

    private function decimal(mixed $raw, string $field, bool $allowZero = false): string
    {
        if (!is_string($raw) || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $raw) !== 1
            || strlen($raw) > 100) {
            throw new InvalidArgumentException('Binance '.$field.' must be a decimal string.');
        }
        if (!$allowZero && preg_match('/^0+(?:\.0+)?$/D', $raw) === 1) {
            throw new InvalidArgumentException('Binance '.$field.' must be positive.');
        }
        return $raw;
    }
}
