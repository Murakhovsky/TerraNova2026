<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Model\AgentRunHistory;

interface AgentTraceRepositoryInterface extends AgentTraceSinkInterface
{
    public function find(string $runId): ?AgentRunHistory;

    public function findByCorrelationId(OrganizationId $organizationId, string $correlationId): ?AgentRunHistory;
}
