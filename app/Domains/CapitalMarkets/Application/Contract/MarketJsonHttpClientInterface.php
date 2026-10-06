<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface MarketJsonHttpClientInterface
{
    /**
     * @param list<string> $headers
     * @param list<string> $allowedHosts
     */
    public function get(
        string $organizationId,
        string $serviceKey,
        string $url,
        array $headers,
        array $allowedHosts,
    ):string;
}
