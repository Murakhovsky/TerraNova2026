<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Onboarding
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $employeeId,
    ) {
        if (trim($this->id) === '' || trim($this->employeeId) === '') {
            throw new InvalidArgumentException('Invalid HR onboarding.');
        }
    }
}
