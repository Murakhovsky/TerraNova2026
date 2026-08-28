<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface InboundLeadRepositoryInterface
{
    /** @param array<string, mixed> $lead */
    public function create(string $organizationId, array $lead): int;
}
