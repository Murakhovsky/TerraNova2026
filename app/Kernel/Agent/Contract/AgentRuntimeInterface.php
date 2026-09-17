<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentInstance;
use Kernel\Agent\Model\AgentRun;

interface AgentRuntimeInterface
{
    public function execute(AgentInstance $instance, AgentContext $context): AgentRun;
}
