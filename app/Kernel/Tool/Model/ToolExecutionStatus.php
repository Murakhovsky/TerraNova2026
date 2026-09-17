<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

enum ToolExecutionStatus: string
{
    case CREATED = 'created';
    case AUTHORIZED = 'authorized';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case DENIED = 'denied';
}
