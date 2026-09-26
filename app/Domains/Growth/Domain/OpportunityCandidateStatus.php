<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

enum OpportunityCandidateStatus: string
{
    case Detected = 'detected';
    case Enriching = 'enriching';
    case Researched = 'researched';
    case Scored = 'scored';
    case Qualified = 'qualified';
    case ReadyForHandoff = 'ready_for_handoff';
    case HandoffPending = 'handoff_pending';
    case HandedOff = 'handed_off';

    case Monitoring = 'monitoring';
    case Disqualified = 'disqualified';
    case Duplicate = 'duplicate';
    case Expired = 'expired';
    case RejectedByTargetDomain = 'rejected_by_target_domain';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Disqualified,
            self::Duplicate,
            self::Expired,
        ], true);
    }
}
