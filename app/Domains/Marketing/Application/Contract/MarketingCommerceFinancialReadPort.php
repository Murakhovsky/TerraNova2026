<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface MarketingCommerceFinancialReadPort
{
    /** Null means no confirmed Finance evidence. */
    public function financial(TenantContext $actor,string $orderId):?array;
}
