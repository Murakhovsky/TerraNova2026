<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyPublicIntakeRepositoryInterface
{
    /** @param array<string,mixed> $submission */
    public function create(string $organizationId, array $submission): int;
}
