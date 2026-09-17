<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

enum ActivityStatus: string
{
    case STARTED = 'started';
    case WAITING = 'waiting';
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case DENIED = 'denied';
    case CANCELLED = 'cancelled';
}
