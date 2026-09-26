<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetPropertySubmissionWorkspaceQuery implements QueryInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $submissionId,
    ) {
    }
}
