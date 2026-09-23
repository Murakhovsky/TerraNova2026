<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthExperimentAssignmentSource:string
{
    case Deterministic='deterministic';
    case Manual='manual';
}
