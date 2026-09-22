<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\HandoffTargetResult;
use Domains\Growth\Application\DTO\OpportunityHandoff;

interface GrowthHandoffTargetInterface
{
    public function domain(): string;

    public function accept(
        OpportunityHandoff $handoff,
        string $correlationId,
        string $idempotencyKey,
    ): HandoffTargetResult;
}
