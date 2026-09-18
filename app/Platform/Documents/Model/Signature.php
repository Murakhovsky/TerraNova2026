<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Signature
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $documentId,
        public string $signerId,
    ) {
        if (trim($this->id) === '' || trim($this->documentId) === '' || trim($this->signerId) === '') {
            throw new InvalidArgumentException('Invalid Documents signature.');
        }
    }
}
