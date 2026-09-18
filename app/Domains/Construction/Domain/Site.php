<?php
declare(strict_types=1);

namespace Domains\Construction\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Site
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $projectId,
        public string $name,
    ) {
        if (trim($this->id) === '' || trim($this->projectId) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Invalid Construction site.');
        }
    }
}
