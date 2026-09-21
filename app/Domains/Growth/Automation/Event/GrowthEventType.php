<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

final class GrowthEventType
{
    public const SIGNAL_DETECTED = 'growth.signal.detected';
    public const CANDIDATE_DETECTED = 'growth.candidate.detected';
    public const CANDIDATE_RESEARCHED = 'growth.candidate.researched';
    public const CANDIDATE_SCORED = 'growth.candidate.scored';
    public const CANDIDATE_QUALIFIED = 'growth.candidate.qualified';
    public const CANDIDATE_MONITORING_STARTED = 'growth.candidate.monitoring_started';
    public const CANDIDATE_DISQUALIFIED = 'growth.candidate.disqualified';
    public const HANDOFF_PREPARED = 'growth.handoff.prepared';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::SIGNAL_DETECTED,
            self::CANDIDATE_DETECTED,
            self::CANDIDATE_RESEARCHED,
            self::CANDIDATE_SCORED,
            self::CANDIDATE_QUALIFIED,
            self::CANDIDATE_MONITORING_STARTED,
            self::CANDIDATE_DISQUALIFIED,
            self::HANDOFF_PREPARED,
        ];
    }
}
