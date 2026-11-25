<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesOrderJourneyStore extends SalesCommerceOrderJourneyReadPort
{
    public function recordJourney(string $org,string $order,string $store,string $external,array $journey):void;
}
