<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingAnalystRunStore
{
    /** @return array<string,mixed>|null */
    public function byKey(string $org,string $key):?array;
    /** @param array<string,mixed> $claim @return array{claimed:bool,run:array<string,mixed>} */
    public function claim(string $org,string $key,array $claim,int $dailyLimit):array;
    /** @param array<string,mixed>|null $advice @return array<string,mixed> */
    public function finish(string $org,string $id,?array $advice):array;
}
