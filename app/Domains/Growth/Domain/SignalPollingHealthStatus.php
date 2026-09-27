<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum SignalPollingHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case CoolingDown = 'cooling_down';
}
