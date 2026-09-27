<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\EngagementActivationMode;

interface GrowthEngagementActivationProviderInterface
{
    public function modeFor(string $organizationId,string $channel):EngagementActivationMode;
}
