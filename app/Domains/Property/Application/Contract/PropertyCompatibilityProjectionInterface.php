<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyCompatibilityProjectionInterface
{
    public function sync(string $organizationId, string $assetId): ?int;
}
