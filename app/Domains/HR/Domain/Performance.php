<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Performance
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $employeeId,
        public string $period,
    ) {
        if (trim($this->id) === '' || trim($this->employeeId) === '' || trim($this->period) === '') {
            throw new InvalidArgumentException('Invalid HR performance.');
        }
    }
}
