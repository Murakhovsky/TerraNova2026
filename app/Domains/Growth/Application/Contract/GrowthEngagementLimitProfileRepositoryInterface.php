<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementLimitProfileRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function latest(string $organizationId):?array;

    /** @param array<string,mixed> $profile */
    public function append(array $profile):void;
}
