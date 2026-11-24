<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesTeamSummaryReadPort
{
    /** @param list<int> $owners Empty population must produce empty totals, never tenant scope. */
    public function summaryForOwners(string $org,array $owners,?string $pipeline=null):array;
}
