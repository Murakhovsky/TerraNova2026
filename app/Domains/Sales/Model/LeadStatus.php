<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum LeadStatus: string
{
    use HasStringValues;

    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case ViewingPlanned = 'viewing_planned';
    case Viewing = 'viewing';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case Spam = 'spam';
    case Closed = 'closed';
}
