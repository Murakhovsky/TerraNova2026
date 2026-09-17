<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Company;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Company
{
    public function __construct(
        public CompanyId $id,
        public OrganizationId $organizationId,
        public string $name,
        public ?string $website = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Company name is required.');
        }
    }
}
