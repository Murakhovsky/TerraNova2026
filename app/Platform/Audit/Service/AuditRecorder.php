<?php
declare(strict_types=1);

namespace Platform\Audit\Service;

use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;

final readonly class AuditRecorder
{
    public function __construct(private AuditSinkInterface $sink)
    {
    }

    public function record(ActivityRecord $record): void
    {
        $this->sink->append($record);
    }
}
