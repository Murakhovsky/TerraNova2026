<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;

interface AgentResultValidatorInterface
{
    public function validate(AgentResult $result, AgentDefinition $agent): void;
}
