<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
/** Internal tenant-scoped ID discovery, no contact data or campaign assignment. */
interface SalesAttributionCandidateReadPort
{
    /** Dates are inclusive native calendar bounds. Cursor is exclusive; no stable census is implied. */
    public function page(string $org,string $kind,string $from,string $to,?string $after,int $limit):array;
}
