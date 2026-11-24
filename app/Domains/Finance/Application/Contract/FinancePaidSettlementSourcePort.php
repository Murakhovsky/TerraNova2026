<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;
use Kernel\Tenant\Model\TenantContext;

/** Owner-scoped cross-domain read ports; source locks are held when called inside the shared Finance transaction. */
interface FinancePaidSettlementSourcePort
{
    public function marketing(TenantContext $actor,string $planId,string $snapshotRef):array;
    public function document(TenantContext $actor,string $documentRef):array;
}
