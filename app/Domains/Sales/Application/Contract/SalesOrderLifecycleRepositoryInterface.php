<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

interface SalesOrderLifecycleRepositoryInterface
{
    public function allocations(string $org,string $order):array;
    public function addAllocation(string $org,string $order,array $allocation):void;
    public function updateAllocation(string $org,string $order,array $allocation):void;
    public function transition(string $org,string $order,int $revision,string $status,array $evidence):void;
}
