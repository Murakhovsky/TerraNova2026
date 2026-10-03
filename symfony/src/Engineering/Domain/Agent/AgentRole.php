<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

enum AgentRole: string
{
    case ENGINEERING_MANAGER = 'ENGINEERING_MANAGER';
    case PRINCIPAL_ARCHITECT = 'PRINCIPAL_ARCHITECT';
    case DEVELOPER = 'DEVELOPER';
    case REVIEWER = 'REVIEWER';
    case QA = 'QA';
}
