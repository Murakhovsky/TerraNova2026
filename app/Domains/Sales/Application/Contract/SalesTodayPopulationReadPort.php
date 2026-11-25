<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesTodayPopulationReadPort
{
    /** @param list<int>|null $owners Null is authorized tenant; empty is empty. */
    public function todayForOwners(string $organizationId,?array $owners):array;
}
