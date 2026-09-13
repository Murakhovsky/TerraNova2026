<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\AgentContextBuilderInterface;

interface AgentProvidingModuleInterface
{
    /** @return array<string, AgentDefinition> Indexed by agent name. */
    public function agents(): array;

    /** @return array<string, AgentContextBuilderInterface> Indexed by agent name. */
    public function agentContextBuilders(): array;
}
