<?php
declare(strict_types=1);

namespace Domains\Construction\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Estimate
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $projectId,
        public Money $total,
    ) {
        if (trim($this->id) === '' || trim($this->projectId) === '') {
            throw new InvalidArgumentException('Estimate id and project id are required.');
        }
    }
}
