<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

use Platform\Integration\Model\ExternalApiResponse;

interface ExternalApiClientInterface
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $options */
    public function request(string $method, string $resource, array $payload = [], array $options = []): ExternalApiResponse;
}
