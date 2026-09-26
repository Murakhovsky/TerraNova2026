<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetContentAdministrationQuery implements QueryInterface
{
    /** @param array<string,mixed> $filters */
    public function __construct(public array $filters = [])
    {
    }
}
