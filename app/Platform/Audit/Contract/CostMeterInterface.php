<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

interface CostMeterInterface
{
    /** @param array<string,mixed> $usage @return array{amount:float,unit:string}|null */
    public function measure(string $component, array $usage): ?array;
}
