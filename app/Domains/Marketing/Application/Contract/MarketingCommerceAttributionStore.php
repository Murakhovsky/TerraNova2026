<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
interface MarketingCommerceAttributionStore
{
    public function record(string $org,string $campaign,array $evidence):array;
    public function list(string $org,string $campaign,?string $plan,string $from,string $to):array;
}
