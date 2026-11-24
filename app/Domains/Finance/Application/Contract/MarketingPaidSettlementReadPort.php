<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface MarketingPaidSettlementReadPort
{
    public function marketing(TenantContext $actor,string $planId,string $snapshotRef):array;
}
