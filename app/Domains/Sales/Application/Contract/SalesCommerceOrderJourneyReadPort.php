<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
/** Optional immutable source evidence. Conflict is not a campaign assignment. */
interface SalesCommerceOrderJourneyReadPort
{
    public function journey(string $org,string $order):?array;
}
