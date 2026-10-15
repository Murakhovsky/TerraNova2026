<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface StreamingWorkerLeaseInterface
{
    public function acquire(string $organizationId,string $sourceId,string $shard,string $workerId,int $ttlSeconds=30):?int;
    public function renew(string $organizationId,string $sourceId,string $shard,string $workerId,int $fencingToken,int $ttlSeconds=30):bool;
    public function owns(string $organizationId,string $sourceId,string $shard,string $workerId,int $fencingToken):bool;
    public function release(string $organizationId,string $sourceId,string $shard,string $workerId,int $fencingToken):void;
}
