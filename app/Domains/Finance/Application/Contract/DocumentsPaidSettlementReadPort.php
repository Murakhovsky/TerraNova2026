<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface DocumentsPaidSettlementReadPort
{
    public function document(TenantContext $actor,string $documentRef):array;
}
