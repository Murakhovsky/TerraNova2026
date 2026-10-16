<?php
declare(strict_types=1);

namespace Platform\Workflow\Model;

enum WorkflowStepSelectionState: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case WAITING = 'waiting';
    case AMBIGUOUS = 'ambiguous';
    case COMPLETE = 'complete';
}
