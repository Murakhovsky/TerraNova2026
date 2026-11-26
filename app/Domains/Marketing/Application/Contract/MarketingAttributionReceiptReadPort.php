<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
interface MarketingAttributionReceiptReadPort
{
    /** Read-only immutable native capture receipt, scoped to exact tenant and key. */
    public function receipt(string $org,string $key):?array;
}
