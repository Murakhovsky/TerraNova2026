<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

final class GrowthEventType
{
    public const ICP_DRAFTED = 'growth.icp.drafted';
    public const ICP_REVISED = 'growth.icp.revised';
    public const ICP_ACTIVATED = 'growth.icp.activated';
    public const ACCOUNT_DISCOVERED = 'growth.account.discovered';
    public const ACCOUNT_SNAPSHOT_CAPTURED = 'growth.account.snapshot_captured';
    public const ACCOUNT_ICP_SCORED = 'growth.account.icp_scored';

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
            self::ICP_DRAFTED,
            self::ICP_REVISED,
            self::ICP_ACTIVATED,
            self::ACCOUNT_DISCOVERED,
            self::ACCOUNT_SNAPSHOT_CAPTURED,
            self::ACCOUNT_ICP_SCORED,
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
