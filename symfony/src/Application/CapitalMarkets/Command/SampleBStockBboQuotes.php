<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Command;

/** Deliberately bounded REST fallback, never a claim of live streaming. */
final readonly class SampleBStockBboQuotes
{
    public function __construct(public string $organizationId,public int $actorId,public int $intervalSeconds) {}
}
