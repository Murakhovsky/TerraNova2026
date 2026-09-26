<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\EngagementExecutionLimitPolicy;

interface GrowthEngagementLimitProviderInterface
{
    public function policyFor(string $organizationId):EngagementExecutionLimitPolicy;
}
