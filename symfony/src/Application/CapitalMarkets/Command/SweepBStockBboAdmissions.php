<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Command;

/** Periodic subscription DB admissibility fence. Does not connect to an exchange. */
final readonly class SweepBStockBboAdmissions
{
    public function __construct(public string $organizationId,public int $actorId) {}
}
