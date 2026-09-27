<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

enum TruthLevel: string
{
    case Observed = 'OBSERVED';
    case Reported = 'REPORTED';
    case Calculated = 'CALCULATED';
    case Derived = 'DERIVED';
    case Inferred = 'INFERRED';
    case Estimated = 'ESTIMATED';
    case Assumed = 'ASSUMED';
}
