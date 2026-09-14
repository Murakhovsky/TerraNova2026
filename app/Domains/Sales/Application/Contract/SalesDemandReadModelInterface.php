<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface SalesDemandReadModelInterface
{
    /** @return list<array{client_case_id:int,property_id:int,match_status:string,score:mixed}> */
    public function activePropertyInterests(string $organizationId): array;

    /** @return array{active_cases:int,cases_with_property_matches:int} */
    public function coverage(string $organizationId): array;
}
