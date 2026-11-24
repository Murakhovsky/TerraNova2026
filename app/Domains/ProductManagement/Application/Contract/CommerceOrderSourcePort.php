<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Trusted provider observations; never provider writes or Sales state. No credentials or customer PII leave this boundary. */
interface CommerceOrderSourcePort
{
    public function order(string $org,string $channel,string $connection,string $externalId):array;
    public function page(string $org,string $channel,string $connection,?string $cursor,?string $cutoff=null):array;
    public function pendingPage(string $org,string $channel,string $connection,?string $cursor):array;
    public function pending(string $org,string $channel,string $connection):array;
    public function assertDelivery(string $org,string $channel,string $connection,string $delivery,string $externalId):void;
    public function acknowledge(string $org,string $channel,string $connection,string $delivery,string $externalId):void;
}
