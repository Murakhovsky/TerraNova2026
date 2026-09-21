<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

enum FeatureFlagOverrideScope: string
{
    case Organization = 'ORGANIZATION';
    case User = 'USER';
}
