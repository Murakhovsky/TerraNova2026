<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

use InvalidArgumentException;
use Kernel\Workflow\Model\WorkflowStatus;

/** Compare-and-swap transition intent. Application policy remains authoritative. */
final readonly class WorkflowRunTransition
{
    public function __construct(
        public WorkflowRunCommand $command,
        public WorkflowStatus $expectedState,
        public WorkflowStatus $nextState,
        public string $reason,
    ) {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Workflow transition must record a reason.');
        }
    }
}
