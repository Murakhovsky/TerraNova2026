<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Execution;

enum PartialFillPolicy:string
{
    case AcceptAndHedgeFilled='ACCEPT_AND_HEDGE_FILLED';
    case CancelRemainder='CANCEL_REMAINDER';
    case Retry='RETRY';
    case AbortAndCompensate='ABORT_AND_COMPENSATE';
}
