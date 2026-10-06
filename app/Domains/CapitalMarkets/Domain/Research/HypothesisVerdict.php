<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum HypothesisVerdict:string
{
    case InsufficientData='INSUFFICIENT_DATA';
    case EdgeExists='EDGE_EXISTS';
    case EdgeExistsNotExecutable='EDGE_EXISTS_BUT_NOT_EXECUTABLE';
    case PaperValidationRequired='PAPER_VALIDATION_REQUIRED';
    case NoEdge='NO_EDGE';
}
