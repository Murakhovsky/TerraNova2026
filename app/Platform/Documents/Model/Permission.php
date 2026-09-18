<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Permission
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $documentId,
        public string $subjectId,
        public string $level,
    ) {
        if (trim($this->id) === '' || trim($this->documentId) === '' || trim($this->subjectId) === '' || trim($this->level) === '') {
            throw new InvalidArgumentException('Invalid Documents permission.');
        }
    }
}
