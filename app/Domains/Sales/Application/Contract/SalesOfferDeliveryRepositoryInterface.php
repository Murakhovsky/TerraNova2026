<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesOfferDeliveryRepositoryInterface
{
    public function recipient(string $org,int $deal):?array;
    public function get(string $org,string $offer,int $version):?array;
    public function record(string $org,string $offer,int $version,string $notification,string $recipientHash,string $documentHash,string $action):void;
}
