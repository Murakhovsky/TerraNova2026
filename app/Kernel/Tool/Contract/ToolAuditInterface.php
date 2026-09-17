<?php
declare(strict_types=1);

namespace Kernel\Tool\Contract;

use Kernel\Tool\Model\ToolExecution;

interface ToolAuditInterface
{
    public function record(ToolExecution $execution): void;
}
