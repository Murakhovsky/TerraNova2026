<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Pipeline;

use Kernel\Shared\Domain\OrganizationId;

interface PipelineRepositoryInterface
{
    public function find(OrganizationId $organizationId, PipelineId $pipelineId): ?Pipeline;
    public function defaultFor(OrganizationId $organizationId): ?Pipeline;
}
