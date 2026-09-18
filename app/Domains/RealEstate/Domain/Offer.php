<?php
declare(strict_types=1);

namespace Domains\RealEstate\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Offer
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $propertyId,
        public string $partyId,
        public Money $amount,
    ) {
        if (trim($this->id) === '' || trim($this->propertyId) === '' || trim($this->partyId) === '') {
            throw new InvalidArgumentException('Offer id, property id and party id are required.');
        }
    }
}
