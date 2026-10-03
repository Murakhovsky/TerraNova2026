<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Finding;

enum FindingCategory: string
{
    case SECURITY = 'SECURITY';
    case TENANT = 'TENANT';
    case AUTH = 'AUTH';
    case DATABASE = 'DATABASE';
    case MIGRATION = 'MIGRATION';
    case BREAKING_CHANGE = 'BREAKING_CHANGE';
    case API = 'API';
    case PERFORMANCE = 'PERFORMANCE';
    case DATA_LOSS = 'DATA_LOSS';
    case UX = 'UX';
    case DEPENDENCY = 'DEPENDENCY';
    case DEPLOYMENT = 'DEPLOYMENT';
    case UNKNOWN_SCOPE = 'UNKNOWN_SCOPE';
    case QUALITY = 'QUALITY';
}
