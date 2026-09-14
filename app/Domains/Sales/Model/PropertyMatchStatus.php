<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum PropertyMatchStatus: string
{
    use HasStringValues;

    case Suggested = 'suggested';
    case Sent = 'sent';
    case Interested = 'interested';
    case Viewing = 'viewing';
    case Rejected = 'rejected';
    case Deal = 'deal';
}
