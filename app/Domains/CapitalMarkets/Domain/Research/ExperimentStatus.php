<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Research;

enum ExperimentStatus:string
{
    case Draft='DRAFT';
    case Queued='QUEUED';
    case Running='RUNNING';
    case Completed='COMPLETED';
    case Failed='FAILED';
    case Cancelled='CANCELLED';
    case Invalidated='INVALIDATED';
}
