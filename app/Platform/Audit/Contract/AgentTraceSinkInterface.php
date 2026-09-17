<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

use Platform\Audit\Model\AgentRunHistory;

interface AgentTraceSinkInterface
{
    public function save(AgentRunHistory $history): void;
}
