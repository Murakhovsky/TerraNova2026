<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/** Private Finance persistence boundary. Only the approved application service may invoke the writer. */
interface FinancePaidSettlementStore
{
    public function settle(string $org,string $reservationId,string $actor,string $action,string $trace,
        int $expectedRevision,string $candidateHash,array $evidence):array;
    public function receipt(string $org,string $reservationId):?array;
}
