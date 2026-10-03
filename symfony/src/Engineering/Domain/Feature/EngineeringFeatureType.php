<?php
declare(strict_types=1);

namespace App\Engineering\Domain\Feature;

enum EngineeringFeatureType: string
{
    case FEATURE = 'FEATURE';
    case BUG = 'BUG';
    case REFACTOR = 'REFACTOR';
    case MIGRATION = 'MIGRATION';
    case MAINTENANCE = 'MAINTENANCE';
}
