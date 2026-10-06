<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Research;
enum ResearchResultStatus:string
{
    case Validated='VALIDATED';
    case PartiallyValidated='PARTIALLY_VALIDATED';
    case InsufficientData='INSUFFICIENT_DATA';
    case NotExecutable='NOT_EXECUTABLE';
    case Rejected='REJECTED';
}
