<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use DateTimeImmutable;

/** Count deduplicated recorded Growth replies linked to already verified Action IDs. */
interface GrowthRunLinkedResponseReadModelInterface
{
    /** @param list<string> $actionIds */
    public function countForActions(
        string $organizationId,
        array $actionIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int;
}
