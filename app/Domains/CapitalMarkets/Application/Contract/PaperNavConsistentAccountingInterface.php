<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

/** An atomic, tenant-scoped REPEATABLE READ view of the paper capital book. */
interface PaperNavConsistentAccountingInterface
{
    /** @return array{portfolio:?array,positions:array,balances:array,executions:array,ledger:array} */
    public function paperAccountingSnapshot(string $organizationId):array;
}
