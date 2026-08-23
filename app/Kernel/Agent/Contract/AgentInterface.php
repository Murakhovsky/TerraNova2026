<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

use Kernel\Agent\AgentResult;

interface AgentInterface
{
    public function name(): string;
    public function analyze(array $context): AgentResult;
}
