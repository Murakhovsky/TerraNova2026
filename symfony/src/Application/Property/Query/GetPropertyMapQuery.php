<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPropertyMapQuery implements QueryInterface
{
    /** @param array<string,mixed> $filters */
    public function __construct(public array $filters = [])
    {
    }
}
