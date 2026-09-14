<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\LlmResponse;

interface LlmClientInterface
{
    public function structured(AgentDefinition $agent, string $question, array $context): LlmResponse;
}
