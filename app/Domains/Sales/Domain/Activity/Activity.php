<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Activity;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Activity
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public ActivityId $id,
        public OrganizationId $organizationId,
        public ActivityType $type,
        public ActivitySubjectType $subjectType,
        public string $subjectId,
        public DateTimeImmutable $occurredAt,
        public array $payload = [],
    ) {
        if (trim($subjectId) === '') {
            throw new InvalidArgumentException('Activity subject id is required.');
        }
    }
}
