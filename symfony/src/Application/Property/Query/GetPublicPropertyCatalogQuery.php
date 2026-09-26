<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPublicPropertyCatalogQuery implements QueryInterface
{
    /** @param array<string,mixed> $query */
    public function __construct(public array $query = [])
    {
    }
}
