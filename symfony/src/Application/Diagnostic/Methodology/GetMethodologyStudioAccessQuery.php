<?php

declare(strict_types=1);

namespace App\Application\Diagnostic\Methodology;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetMethodologyStudioAccessQuery implements QueryInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $userId,
    ) {
    }
}
