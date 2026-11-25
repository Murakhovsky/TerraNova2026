<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;
interface MarketingMediaAnalyticsStore
{
    public function byKey(string $org,string $key,string $requestHash):?array;
    public function commit(string $org,string $actor,string $key,string $requestHash,array $account,string $grain,string $from,string $to,array $rows):array;
    public function get(string $org,string $id):?array;
    public function latest(string $org,string $account,string $grain,string $from,string $to):?array;
}
