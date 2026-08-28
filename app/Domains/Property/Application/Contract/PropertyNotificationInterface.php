<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

interface PropertyNotificationInterface
{
    public function notifyPropertySubmission(int $submissionId): void;

    public function notifySubmissionStatus(int $submissionId, string $status, string $note = '', ?int $propertyId = null): void;

    public function notifyPropertyStatus(int $propertyId, string $status, string $note = ''): void;

    public function notifyPresentationShared(int $propertyId, ?int $userId, string $channel, string $variant): void;
}
