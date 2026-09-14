<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentInvocation;
use Kernel\Agent\AgentResult;
use Kernel\Agent\LlmResponse;
use Throwable;

interface AgentRunRepositoryInterface
{
    public function start(string $runId, AgentDefinition $agent, AgentInvocation $invocation, array $context): void;
    public function complete(string $runId, AgentResult $result, LlmResponse $response, int $durationMs): void;
    public function fail(string $runId, Throwable $error, int $durationMs, bool $invalidOutput = false): void;
}
