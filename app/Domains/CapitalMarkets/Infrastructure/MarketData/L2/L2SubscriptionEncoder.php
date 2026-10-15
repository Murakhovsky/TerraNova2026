<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;
use DomainException;

/** Serializes subscription control frames without injecting credentials. */
final readonly class L2SubscriptionEncoder
{
    public function encode(L2SubscriptionConfig $config): ?string
    {
        $frame = $config->subscribeFrame();
        if ($frame === null) return null;
        $json = json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 4096) throw new DomainException('Subscription frame exceeds limit.');
        return $json;
    }
}
