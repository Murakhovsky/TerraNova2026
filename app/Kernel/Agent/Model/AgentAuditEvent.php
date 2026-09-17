<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

enum AgentAuditEvent: string
{
    case REASONING_REQUEST = 'reasoning_request';
    case REASONING_RESULT = 'reasoning_result';
    case DECISION = 'decision';
    case ACTION = 'action';
    case RESULT = 'result';
    case FAILURE = 'failure';
}
