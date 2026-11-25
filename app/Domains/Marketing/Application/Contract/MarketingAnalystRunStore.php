<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingAnalystRunStore
{
    /** @return list<array<string,mixed>> */
    public function listRuns(string $org,string $campaignId,int $limit=50,int $offset=0):array;
    /** @return array<string,mixed>|null */
    public function get(string $org,string $id):?array;
    /** @param array<string,mixed> $decision @param callable(array<string,mixed>):void $validateFresh @return array<string,mixed> */
    public function decide(string $org,string $id,string $campaignId,string $actor,string $key,array $decision,callable $validateFresh):array;
    /** @return array<string,mixed>|null */
    public function byKey(string $org,string $key):?array;
    /** @param array<string,mixed> $claim @return array{claimed:bool,run:array<string,mixed>} */
    public function claim(string $org,string $key,array $claim,int $dailyLimit):array;
    /** @param array<string,mixed>|null $advice @return array<string,mixed> */
    public function finish(string $org,string $id,?array $advice):array;
}
