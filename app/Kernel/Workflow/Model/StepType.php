<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

enum StepType: string
{
    case HUMAN = 'human';
    case AGENT = 'agent';
    case TOOL = 'tool';
    case SYSTEM = 'system';
    case DECISION = 'decision';
    case WAIT = 'wait';
}
