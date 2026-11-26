<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

interface MarketingOptimizationReceiptStore
{
    public function byKey(string $org,string $key):?array;
    public function get(string $org,string $id):?array;
    public function create(string $org,string $key,string $requestHash,array $receipt):array;
}
