<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Task;

enum EngineeringTaskType: string
{
    case ARCHITECTURE = 'ARCHITECTURE';
    case BACKEND = 'BACKEND';
    case FRONTEND = 'FRONTEND';
    case DATABASE = 'DATABASE';
    case TEST = 'TEST';
    case DOCUMENTATION = 'DOCUMENTATION';
    case REVIEW = 'REVIEW';
    case SECURITY = 'SECURITY';
    case DEVOPS = 'DEVOPS';
    case RESEARCH = 'RESEARCH';
}
