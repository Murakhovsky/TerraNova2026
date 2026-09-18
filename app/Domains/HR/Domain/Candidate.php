<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Candidate
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $fullName,
        public string $desiredPosition,
    ) {
        if (trim($this->id) === '' || trim($this->fullName) === '' || trim($this->desiredPosition) === '') {
            throw new InvalidArgumentException('Invalid HR candidate.');
        }
    }
}
