<?php

declare(strict_types=1);

namespace App\Application\Administration\Query;

use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetAdministrationUsersQueryHandler implements QueryHandlerInterface
{
    public function __construct(private AdministrationServiceInterface $administration)
    {
    }

    /** @return array{filters:array<string,mixed>,users:list<array<string,mixed>>,stats:array<string,mixed>} */
    public function __invoke(GetAdministrationUsersQuery $query): array
    {
        $filters = $this->administration->userFilters($query->filters);

        return [
            'filters' => $filters,
            'users' => $this->administration->users($filters),
            'stats' => $this->administration->userStats(),
        ];
    }
}
