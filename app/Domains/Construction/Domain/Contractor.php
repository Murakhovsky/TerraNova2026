<?php
declare(strict_types=1);

namespace Domains\Construction\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Contractor
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $name,
    ) {
        if (trim($this->id) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Invalid Construction contractor.');
        }
    }
}
