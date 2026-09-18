<?php
declare(strict_types=1);

namespace Domains\RealEstate\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Mandate
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $propertyId,
        public string $partyId,
    ) {
        if (trim($this->id) === '' || trim($this->propertyId) === '' || trim($this->partyId) === '') {
            throw new InvalidArgumentException('Invalid RealEstate mandate.');
        }
    }
}
