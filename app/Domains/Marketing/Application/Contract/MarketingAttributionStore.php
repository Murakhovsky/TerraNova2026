<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
interface MarketingAttributionStore
{
    public function record(string $org,string $campaign,array $evidence):array;
    public function list(string $org,string $campaign,string $from,string $to,int $limit,int $offset):array;
}
