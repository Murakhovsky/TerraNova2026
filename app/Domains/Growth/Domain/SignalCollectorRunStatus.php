<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum SignalCollectorRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
