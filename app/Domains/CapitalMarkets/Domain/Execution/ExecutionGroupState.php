<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

enum ExecutionGroupState:string
{
    case Created='CREATED';
    case Validating='VALIDATING';
    case Reserving='RESERVING';
    case Ready='READY';
    case Executing='EXECUTING';
    case PartiallyExecuted='PARTIALLY_EXECUTED';
    case Hedging='HEDGING';
    case Completed='COMPLETED';
    case Compensating='COMPENSATING';
    case Failed='FAILED';
    case Cancelled='CANCELLED';
    case Expired='EXPIRED';

    public function terminal():bool
    {
        return in_array($this,[self::Completed,self::Failed,self::Cancelled,self::Expired],true);
    }
}
