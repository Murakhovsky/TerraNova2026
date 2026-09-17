<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

use Platform\Audit\Model\AgentRunHistory;

interface AgentTraceRepositoryInterface extends AgentTraceSinkInterface
{
    public function find(string $runId): ?AgentRunHistory;
}
