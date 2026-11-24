<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
use Kernel\Tenant\Model\TenantContext;
/** Native Sales placement and optional independent source journey. Never a payment or recognized revenue fact. */
interface MarketingCommerceOrderReadPort
{
    public function assertAvailable(TenantContext $actor):void;
    public function get(TenantContext $actor,string $orderId):?array;
}
