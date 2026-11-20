<?php
declare(strict_types=1);
namespace App\Application\Experience\Business\Contract;

interface BusinessEntitySummaryReadInterface
{
    /** @return list<array{label:string,value:string}> */
    public function facts(string $organizationId, string $entityType, string $entityId): array;
}
