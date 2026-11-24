<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesOrderSourceRepositoryInterface
{
    public function lock(string $org,string $channel,string $connection,string $externalId):array;
    public function observe(string $org,string $sourceId,string $updated,string $hash,array $observation,string $outcome,?string $reason,?string $orderId,bool $advance):void;
}
