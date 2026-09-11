<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesIntegrationHealthProbeInterface
{
    /**
     * @return array{status:string,reason:?string}
     */
    public function probe(string $capability, string $provider, array $config, ?string $credentialsReference): array;
}
