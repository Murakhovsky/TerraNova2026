<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentOutput;

interface LlmProviderInterface
{
    public function execute(AgentDefinition $definition, AgentContext $context): AgentOutput;
}
