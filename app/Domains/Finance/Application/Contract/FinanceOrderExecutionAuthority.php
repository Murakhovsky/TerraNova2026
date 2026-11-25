<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/** Internal guard for canonical, independently approved financial Actions. */
interface FinanceOrderExecutionAuthority
{
    public function assertApproved(string $organizationId,string $orderId,string $actionType,array $parameters,
        string $requester,string $actionId,string $correlationId,string $key):void;
}
