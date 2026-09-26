<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetPublicBrandPageQuery implements QueryInterface
{
    public function __construct(public string $slug)
    {
    }
}
