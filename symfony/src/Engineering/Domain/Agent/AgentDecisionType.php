<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

enum AgentDecisionType: string
{
    case RUN_AGENT = 'RUN_AGENT';
    case REQUEST_HUMAN_DECISION = 'REQUEST_HUMAN_DECISION';
    case RETRY = 'RETRY';
    case BLOCK = 'BLOCK';
    case READY_FOR_HUMAN_APPROVAL = 'READY_FOR_HUMAN_APPROVAL';
    case STOP = 'STOP';
}
