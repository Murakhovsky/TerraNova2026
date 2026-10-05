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

    /** @deprecated V0.1 compatibility for persisted runs and historical eval fixtures. */
    case QA = 'QA';
}
