<?php
declare(strict_types=1);

namespace Domains\RealEstate\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class BrokerageCase
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $propertyId,
        public string $subject,
    ) {
        if (trim($this->id) === '' || trim($this->propertyId) === '' || trim($this->subject) === '') {
            throw new InvalidArgumentException('Invalid RealEstate brokeragecase.');
        }
    }
}
