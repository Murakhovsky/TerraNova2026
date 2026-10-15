<?php
declare(strict_types=1);
namespace App\Application\CapitalMarkets\Command;

/** Opt-in managed intent reconciliation; tenant and actor are explicit. */
final readonly class ReconcileBStocksPoolSubscriptions
{
    public function __construct(public string $organizationId,public int $actorId) {}
}
