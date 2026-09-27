<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum HypothesisStatus: string
{
    case Unverified = 'UNVERIFIED';
    case Supported = 'SUPPORTED';
    case StronglySupported = 'STRONGLY_SUPPORTED';
    case ConfirmedRootCause = 'CONFIRMED_ROOT_CAUSE';
    case Rejected = 'REJECTED';

    // Transitional aliases retained for pre-V1 callers.
    case Open = 'OPEN';
    case Confirmed = 'CONFIRMED';
    case Inconclusive = 'INCONCLUSIVE';
}
