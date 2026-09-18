<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Position
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $title,
    ) {
        if (trim($this->id) === '' || trim($this->title) === '') {
            throw new InvalidArgumentException('Invalid HR position.');
        }
    }
}
