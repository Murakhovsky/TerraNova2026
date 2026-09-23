<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSalesTodayQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales)
    {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function __invoke(GetSalesTodayQuery $query): array
    {
        return $this->sales->today(
            $query->organizationId->value(),
            $query->ownerId,
        );
    }
}
