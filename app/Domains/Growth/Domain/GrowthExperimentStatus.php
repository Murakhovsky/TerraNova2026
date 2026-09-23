<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum GrowthExperimentStatus:string
{
    case Draft='draft';
    case Running='running';
    case Paused='paused';
    case Completed='completed';
    case Archived='archived';
}
