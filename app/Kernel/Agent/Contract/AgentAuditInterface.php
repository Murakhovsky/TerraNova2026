<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\Model\AgentAuditEvent;

interface AgentAuditInterface
{
    /** @param array<string,mixed> $payload */
    public function record(
        string $runId,
        AgentDefinition $agent,
        AgentInvocation $invocation,
        AgentAuditEvent $event,
        array $payload = [],
        ?int $durationMs = null,
        ?float $cost = null,
        ?string $costUnit = null,
        ?string $error = null,
    ): void;
}
