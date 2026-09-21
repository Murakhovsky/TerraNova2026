<?php
declare(strict_types=1);

namespace Kernel\Action;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RuntimeActionProjection
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $type,
        public ?string $targetType,
        public ?string $targetId,
        public string $sourceType,
        public string $sourceId,
        public ActionStatus $status,
        public string $executionMode,
        public string $riskLevel,
        public DateTimeImmutable $createdAt,
        public ?string $approvalId = null,
        public ?string $approvalStatus = null,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/', $this->id)) {
            throw new InvalidArgumentException('Runtime Action projection id must be a canonical 32-character hex id.');
        }

        if (trim($this->organizationId) === '' || trim($this->type) === '') {
            throw new InvalidArgumentException('Runtime Action projection requires organization and type.');
        }

        if (($this->targetType === null) !== ($this->targetId === null)) {
            throw new InvalidArgumentException('Runtime Action target type and id must either both exist or both be null.');
        }
    }
}
