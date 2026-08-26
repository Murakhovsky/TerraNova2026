<?php
declare(strict_types=1);

namespace Terra\Modules\Cli\Tasks;

use Kernel\Agent\Contract\AgentRetentionInterface;
use Phalcon\Cli\Task;

final class AgentTask extends Task
{
    public function purgeInputsAction(): void
    {
        /** @var AgentRetentionInterface $retention */
        $retention = $this->getDI()->getShared('cosAgentRetention');
        echo sprintf('Agent input snapshots purged: %d', $retention->purgeExpiredInputs()) . PHP_EOL;
    }
}
