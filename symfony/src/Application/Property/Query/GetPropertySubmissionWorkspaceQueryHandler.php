<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Domains\Property\Application\Contract\PropertyWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPropertySubmissionWorkspaceQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyWorkspaceReadModelInterface $workspace)
    {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetPropertySubmissionWorkspaceQuery $query): ?array
    {
        return $this->workspace->submission(
            $query->organizationId->value(),
            $query->submissionId,
        );
    }
}
