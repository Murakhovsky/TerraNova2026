<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PresentationSalesInterface
{
    public function activeClientCase(int $caseId): ?array;

    public function recordShare(int $caseId, int $personId, ?int $userId, string $title, string $body, ?int $propertyId, string $matchNote): void;
}
