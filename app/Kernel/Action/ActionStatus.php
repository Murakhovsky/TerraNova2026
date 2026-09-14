<?php
declare(strict_types=1);

namespace Kernel\Action;

enum ActionStatus: string
{
    case Proposed = 'PROPOSED';
    case PendingApproval = 'PENDING_APPROVAL';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Rejected = 'REJECTED';
}
