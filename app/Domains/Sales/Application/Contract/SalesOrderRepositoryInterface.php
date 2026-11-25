<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesOrderRepositoryInterface
{
    public function create(string $org,array $order,array $lines,array $evidence):void;
    public function get(string $org,string $id,bool $lock=false):?array;
    public function customerForDeal(string $org,int $deal):?int;
}
