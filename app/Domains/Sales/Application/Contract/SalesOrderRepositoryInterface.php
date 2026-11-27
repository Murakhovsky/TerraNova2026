<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;
interface SalesOrderRepositoryInterface
{
    public function create(string $org,array $order,array $lines,array $evidence):void;
    public function get(string $org,string $id,bool $lock=false):?array;
    /** Redacted page; a non-null owner population restricts both orders and linked deals. */
    public function workspacePage(string $org,?array $owners,string $query,int $offset,int $limit):array;
    public function customerForDeal(string $org,int $deal):?int;
}
