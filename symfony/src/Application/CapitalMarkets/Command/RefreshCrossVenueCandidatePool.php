<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Command;

/** Explicit tenant-scoped market discovery message; never places orders. */
final readonly class RefreshCrossVenueCandidatePool
{
    public function __construct(public string $organizationId, public int $actorId) {}
}
