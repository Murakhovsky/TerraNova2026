<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

interface FinanceSpendObservationRepositoryInterface
{
    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function record(string $organizationId,array $evidence,string $correlationId):array;
    /** @param array<string,string> $scope @return array<string,mixed> */
    public function reconciliationStatus(string $organizationId,array $scope):array;
}

