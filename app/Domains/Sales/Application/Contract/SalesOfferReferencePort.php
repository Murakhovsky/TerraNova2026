<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesOfferReferencePort
{
    /** Return minimal authoritative reference snapshot; null means unavailable or outside tenant. */
    public function resolve(string $org,array $line):?array;
}
