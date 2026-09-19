<?php
declare(strict_types=1);

namespace Kernel\Visualization\Graph;

interface GraphPayloadMapperInterface
{
    /** @return array<string,mixed> */
    public function map(Graph $graph): array;
}
