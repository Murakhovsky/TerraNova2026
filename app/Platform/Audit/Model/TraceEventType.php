<?php
declare(strict_types=1);

namespace Platform\Audit\Model;

enum TraceEventType: string
{
    case REASONING_REQUEST = 'reasoning_request';
    case REASONING_RESULT = 'reasoning_result';
    case TOOL_CALL = 'tool_call';
    case TOOL_RESULT = 'tool_result';
    case DECISION = 'decision';
    case ACTION = 'action';
    case RESULT = 'result';
    case WORKFLOW_STEP = 'workflow_step';
}
