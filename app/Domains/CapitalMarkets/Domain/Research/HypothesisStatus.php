<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum HypothesisStatus:string
{
    case Idea='IDEA';
    case Draft='DRAFT';
    case DataRequired='DATA_REQUIRED';
    case ReadyForResearch='READY_FOR_RESEARCH';
    case Researching='RESEARCHING';
    case Backtesting='BACKTESTING';
    case OutOfSample='OUT_OF_SAMPLE';
    case Paper='PAPER';
    case Validated='VALIDATED';
    case Rejected='REJECTED';
    case Suspended='SUSPENDED';
    case Archived='ARCHIVED';
}
