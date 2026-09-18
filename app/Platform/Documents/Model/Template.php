<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Template
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $name,
    ) {
        if (trim($this->id) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Invalid Documents template.');
        }
    }
}
