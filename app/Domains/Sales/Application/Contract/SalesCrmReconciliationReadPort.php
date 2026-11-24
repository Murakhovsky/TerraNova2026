<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesCrmReconciliationReadPort
{
    public function reconcile(string $org,int $deal,string $provider,string $kind,string $key):array;
}
