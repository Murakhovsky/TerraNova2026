<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

enum FeatureFlagDecisionReason: string
{
    case UnknownFlag = 'unknown_flag';
    case MasterDisabled = 'master_disabled';
    case UserOverride = 'user_override';
    case OrganizationOverride = 'organization_override';
    case OutsideSchedule = 'outside_schedule';
    case FullRollout = 'full_rollout';
    case ZeroRollout = 'zero_rollout';
    case PercentageRollout = 'percentage_rollout';
    case OutsideRollout = 'outside_rollout';
}
