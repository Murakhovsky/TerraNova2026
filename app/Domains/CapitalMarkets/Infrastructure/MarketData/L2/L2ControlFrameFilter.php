<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;

/** Distinguishes exchange control frames from order-book data. */
final class L2ControlFrameFilter
{
    public function classify(string $venue, string $json): string
    {
        $frame = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($frame) || array_is_list($frame)) {
            throw new DomainException('Invalid L2 frame envelope.');
        }
        if (in_array($venue, ['BINANCE_SPOT', 'BINANCE_FUTURES'], true)) {
            $row = $frame['data'] ?? $frame;
            return is_array($row) && ($row['e'] ?? null) === 'depthUpdate' ? 'DATA' : 'CONTROL';
        }
        if (in_array($venue, ['BYBIT_SPOT', 'BYBIT_LINEAR'], true)) {
            if (($frame['success'] ?? true) === false) {
                throw new DomainException('Bybit subscription rejected.');
            }
            return isset($frame['topic']) ? 'DATA' : 'CONTROL';
        }
        if ($venue === 'KRAKEN_SPOT') {
            if (($frame['success'] ?? true) === false) {
                throw new DomainException('Kraken subscription rejected.');
            }
            return ($frame['channel'] ?? null) === 'book' ? 'DATA' : 'CONTROL';
        }
        throw new DomainException('Unsupported L2 venue.');
    }
}
