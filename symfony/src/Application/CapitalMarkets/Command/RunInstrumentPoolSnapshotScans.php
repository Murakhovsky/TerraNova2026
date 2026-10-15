<?php
declare(strict_types=1);
namespace App\Application\CapitalMarkets\Command;
/** Periodic public catalog discovery, only with explicit tenant/provider/venue configuration. */
final readonly class RunInstrumentPoolSnapshotScans
{
    public function __construct(public string $trigger='scheduler'){}
}
