<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Binance REST depth snapshot, decoded without float conversion. */
final class BinanceL2SnapshotDecoder
{
    /** @return array{sequence:string,bids:list<array{price:string,qty:string}>,asks:list<array{price:string,qty:string}>} */
    public function decode(string $json): array
    {
        $shape = json_decode($json, false, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_object($shape) || !isset($shape->bids, $shape->asks)
            || !is_array($shape->bids) || !is_array($shape->asks)) {
            throw new DomainException('Snapshot sides must be JSON arrays.');
        }
        $row = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($row) || array_is_list($row)) throw new DomainException('Invalid Binance depth snapshot.');
        $id = $row['lastUpdateId'] ?? null;
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id)) throw new DomainException('Invalid snapshot sequence.');
        return ['sequence' => (string) $id, 'bids' => $this->levels($row['bids'] ?? null), 'asks' => $this->levels($row['asks'] ?? null)];
    }

    /**
     * Validate the official Futures WS API request correlation and status,
     * then apply the same exact-decimal order-book validation as HTTPS REST.
     * @return array{sequence:string,bids:array,asks:array}
     */
    public function decodeFuturesWebSocketDepth(string $json, string $requestId): array
    {
        $response = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($response) || array_is_list($response)
            || !hash_equals($requestId, (string)($response['id'] ?? ''))
            || ($response['status'] ?? null) !== 200
            || !is_array($response['result'] ?? null) || array_is_list($response['result'])) {
            throw new DomainException('Binance Futures WS snapshot response invalid or rejected.');
        }
        return $this->decode(json_encode($response['result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function levels(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) throw new DomainException('Invalid snapshot levels.');
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 2) throw new DomainException('Invalid snapshot level.');
            foreach ($row as $value) {
                if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) throw new DomainException('Invalid snapshot decimal.');
            }
            if (preg_match('/^0+(?:\.0+)?$/D', $row[0])) throw new DomainException('Zero snapshot price.');
            $out[] = ['price' => $row[0], 'qty' => $row[1]];
        }
        return $out;
    }
}
