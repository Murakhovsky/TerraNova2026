<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;

interface AgentConfigurationProviderInterface
{
    public function effective(string $organizationId, AgentDefinition $definition): AgentDefinition;
}
