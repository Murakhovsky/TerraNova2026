<?php
declare(strict_types=1);

namespace App\Engineering\Application\Audit;

interface EngineeringAuditQueryInterface
{
    /** @return list<array<string,mixed>> */
    public function forFeature(string $featureId): array;
}
