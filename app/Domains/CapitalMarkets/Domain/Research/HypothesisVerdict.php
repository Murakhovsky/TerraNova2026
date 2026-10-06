<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum HypothesisVerdict:string
{
    case InsufficientSample='INSUFFICIENT_SAMPLE';
    case EdgeNotObserved='EDGE_NOT_OBSERVED';
    case EdgeObservedNotExecutable='EDGE_OBSERVED_NOT_EXECUTABLE';
    case EdgeExecutableUnvalidated='EDGE_EXECUTABLE_UNVALIDATED';
    case EdgeValidated='EDGE_VALIDATED';
    case EdgeNotValidated='EDGE_NOT_VALIDATED';
}
