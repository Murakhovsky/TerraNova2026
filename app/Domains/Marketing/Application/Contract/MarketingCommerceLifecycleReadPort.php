<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;

/** Optional native Sales lifecycle source. Null means unavailable, not a placed-only state. */
interface MarketingCommerceLifecycleReadPort
{
    public function lifecycle(TenantContext $actor,string $orderId,int $afterRevision,int $limit):?array;
}
