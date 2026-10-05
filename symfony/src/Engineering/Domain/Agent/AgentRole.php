<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Agent;

enum AgentRole: string
{
    case ENGINEERING_MANAGER = 'ENGINEERING_MANAGER';
    case PRODUCT_REQUIREMENTS = 'PRODUCT_REQUIREMENTS';
    case QA_PLANNER = 'QA_PLANNER';
    case PRINCIPAL_ARCHITECT = 'PRINCIPAL_ARCHITECT';
    case DEVELOPER = 'DEVELOPER';
    case REVIEWER = 'REVIEWER';
    case QA_EXECUTOR = 'QA_EXECUTOR';
    case INTEGRATION_RELEASE = 'INTEGRATION_RELEASE';

    /** @deprecated compatibility alias for V1 executions; new workflows use QA_PLANNER / QA_EXECUTOR. */
    case QA = 'QA';
}
