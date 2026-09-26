<?php

declare(strict_types=1);

namespace App\Application\Administration\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetAdministrationUsersQuery implements QueryInterface
{
    /** @param array<string,mixed> $filters */
    public function __construct(public array $filters = [])
    {
    }
}
