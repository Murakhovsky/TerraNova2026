<?php
declare(strict_types=1);

namespace Domains\Construction\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Material
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $name,
        public string $unit,
    ) {
        if (trim($this->id) === '' || trim($this->name) === '' || trim($this->unit) === '') {
            throw new InvalidArgumentException('Invalid Construction material.');
        }
    }
}
