<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

interface AgentRetentionInterface
{
    public function purgeExpiredInputs(): int;
}
