<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

enum ClientCompatibilityStatus: string
{
    case Supported = 'supported';
    case UpgradeRecommended = 'upgrade_recommended';
    case UpgradeRequired = 'upgrade_required';
}
