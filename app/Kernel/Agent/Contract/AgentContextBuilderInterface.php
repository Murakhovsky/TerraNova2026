<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentInvocation;

interface AgentContextBuilderInterface
{
    public function build(AgentInvocation $invocation): array;
}
