<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum Severity: string
{
    case None = 'NONE';
    case Info = 'INFO';
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';
}
