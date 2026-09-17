<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Lead;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case ViewingPlanned = 'viewing_planned';
    case Viewing = 'viewing';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case Disqualified = 'disqualified';
    case Spam = 'spam';
    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::Disqualified, self::Spam, self::Closed], true);
    }
}
