<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Maps venue control frames to subscription readiness. */
final class L2SubscriptionAcknowledgement
{
    public function accepted(string $venue, string $json): bool
    {
        $f = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($f) || array_is_list($f)) throw new DomainException('Invalid subscription acknowledgement.');
        if (str_starts_with($venue, 'BINANCE_')) return true;
        if (str_starts_with($venue, 'BYBIT_')) {
            if (isset($f['retCode']) && $f['retCode'] !== 0) throw new DomainException('Bybit subscription error code.');
            if (($f['op'] ?? null) !== 'subscribe') return false;
            if (($f['success'] ?? null) !== true) throw new DomainException('Bybit subscription rejected.');
            return true;
        }
        if ($venue === 'KRAKEN_SPOT') {
            if (isset($f['error'])) throw new DomainException('Kraken subscription error.');
            if (($f['method'] ?? null) !== 'subscribe') return false;
            if (($f['success'] ?? null) !== true) throw new DomainException('Kraken subscription rejected.');
            return true;
        }
        throw new DomainException('Unsupported venue.');
    }
}
