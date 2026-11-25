<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
interface MarketingFinanceAnalyticsReadPort
{
    public function settlement(TenantContext $actor,string $planId,string $planHash,string $reservationId):?array;
}
