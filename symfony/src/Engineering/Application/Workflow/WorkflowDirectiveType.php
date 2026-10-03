<?php
declare(strict_types=1);

namespace App\Engineering\Application\Workflow;

enum WorkflowDirectiveType: string
{
    case RUN_AGENT = 'RUN_AGENT';
    case REQUEST_HUMAN_DECISION = 'REQUEST_HUMAN_DECISION';
    case BLOCK = 'BLOCK';
    case READY_FOR_HUMAN_APPROVAL = 'READY_FOR_HUMAN_APPROVAL';
    case STOP = 'STOP';
}
