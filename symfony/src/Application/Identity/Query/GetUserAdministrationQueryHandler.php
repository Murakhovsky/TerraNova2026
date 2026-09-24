<?php

declare(strict_types=1);

namespace App\Application\Identity\Query;

use Domains\Identity\Application\Contract\AdministrationServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetUserAdministrationQueryHandler implements QueryHandlerInterface
{
    public function __construct(private AdministrationServiceInterface $administration)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetUserAdministrationQuery $query): array
    {
        $filters = $this->administration->userFilters($query->filters);

        return [
            'filters' => $filters,
            'users' => $this->administration->users($filters),
            'stats' => $this->administration->userStats(),
        ];
    }
}
