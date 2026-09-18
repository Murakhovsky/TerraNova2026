<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Version
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $documentId,
        public string $number,
    ) {
        if (trim($this->id) === '' || trim($this->documentId) === '' || trim($this->number) === '') {
            throw new InvalidArgumentException('Invalid Documents version.');
        }
    }
}
