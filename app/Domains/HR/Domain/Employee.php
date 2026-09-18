<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Employee
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $fullName,
        public string $positionId,
    ) {
        if (trim($this->id) === '' || trim($this->fullName) === '' || trim($this->positionId) === '') {
            throw new InvalidArgumentException('Invalid HR employee.');
        }
    }
}
