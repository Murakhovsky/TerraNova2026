<?php

declare(strict_types=1);

namespace Kernel\Queue;

enum AsyncOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
