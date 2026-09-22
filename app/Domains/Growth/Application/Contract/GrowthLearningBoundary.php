<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthLearningBoundary
{
    /** @return array<string,mixed> */
    public function learningBrief(string $organizationId,string $candidateId):array;
}
