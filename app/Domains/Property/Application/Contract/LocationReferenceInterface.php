<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface LocationReferenceInterface
{
    public function resolveOrCreate(string $city, ?string $region = null, ?string $district = null): int;
}
