<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Activity;

enum ActivityType: string
{
    case Note = 'note';
    case Call = 'call';
    case Message = 'message';
    case Meeting = 'meeting';
    case Viewing = 'viewing';
    case Offer = 'offer';
    case StatusChange = 'status_change';
    case Deal = 'deal';
    case Task = 'task';
    case Followup = 'followup';
}
