<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum AssessmentStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case InsufficientData = 'INSUFFICIENT_DATA';
    case Assessed = 'ASSESSED';
    case Good = 'GOOD';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
    case NotApplicable = 'NOT_APPLICABLE';
    case Contradictory = 'CONTRADICTORY';

    // Transitional aliases retained for pre-V1 callers.
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Partial = 'PARTIAL';
    case Unknown = 'UNKNOWN';
}
