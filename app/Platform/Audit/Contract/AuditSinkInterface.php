<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

use Platform\Audit\Model\ActivityRecord;

interface AuditSinkInterface
{
    public function append(ActivityRecord $record): void;
}
