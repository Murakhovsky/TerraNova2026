<?php
declare(strict_types=1);

namespace App\Engineering\Domain\DomainDevelopment;

enum DomainFeatureKind: string
{
    case FOUNDATION = 'FOUNDATION';
    case CORE = 'CORE';
    case INTEGRATION = 'INTEGRATION';
    case APPLICATION = 'APPLICATION';
    case UI = 'UI';
    case INFRASTRUCTURE = 'INFRASTRUCTURE';
}
