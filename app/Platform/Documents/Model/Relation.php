<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Relation
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $documentId,
        public string $relatedType,
        public string $relatedId,
    ) {
        if (trim($this->id) === '' || trim($this->documentId) === '' || trim($this->relatedType) === '' || trim($this->relatedId) === '') {
            throw new InvalidArgumentException('Invalid Documents relation.');
        }
    }
}
