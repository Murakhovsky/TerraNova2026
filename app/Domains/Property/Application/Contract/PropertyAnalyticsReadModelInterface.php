<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyAnalyticsReadModelInterface
{
    /** @return array<string,mixed> */
    public function summary(string $organizationId, int $days = 30): array;

    /** @return list<array<string,mixed>> */
    public function inventorySegments(string $organizationId): array;

    /** @return array<string,list<array<string,mixed>>> */
    public function stock(string $organizationId): array;

    /** @return array<string,int> */
    public function changes(string $organizationId, int $days = 30): array;
}
