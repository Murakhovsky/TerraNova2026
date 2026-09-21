<?php
declare(strict_types=1);

namespace Domains\Growth\Application\UseCase;

use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Domain\OpportunityCandidate;

final readonly class PrepareOpportunityHandoff
{
    public function execute(
        OpportunityCandidate $candidate,
        string $expectedValue,
        string $recommendedPlay,
        string $recommendedAction,
    ): OpportunityHandoff {
        $candidate->prepareHandoff($expectedValue, $recommendedPlay, $recommendedAction);

        return OpportunityHandoff::fromCandidate($candidate);
    }
}
