<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Internal transaction boundary for the single, bounded Product assembly use case. */
interface ProductAssemblyTransactionPort
{
    public function assemble(string $org,array $input,string $key,string $actor,string $trace,callable $apply):array;
}
