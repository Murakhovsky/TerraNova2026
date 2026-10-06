<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

enum PaperOrderState:string
{
    case Created='CREATED';
    case Validated='VALIDATED';
    case Submitted='SUBMITTED';
    case Acknowledged='ACKNOWLEDGED';
    case PartiallyFilled='PARTIALLY_FILLED';
    case Filled='FILLED';
    case CancelPending='CANCEL_PENDING';
    case Cancelled='CANCELLED';
    case Rejected='REJECTED';
    case Failed='FAILED';

    public function terminal():bool
    {
        return in_array($this,[self::Filled,self::Cancelled,self::Rejected,self::Failed],true);
    }
}
