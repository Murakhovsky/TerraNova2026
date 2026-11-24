<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface MarketingSalesAttributionReadPort
{
    /** Minimal native lead and linked deal facts, never customer contact data. */
    public function lead(TenantContext $actor,string $leadId):?array;
}
