<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyAnalyticsInterface
{
    public function recordSubmission(int $submissionId, array $context): void;

    public function recordView(int $propertyId, array $context): void;

    public function recordPresentation(string $eventType, ?int $propertyId, ?int $entityId, ?int $userId, string $sourcePage, array $payload): void;
}
