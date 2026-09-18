<?php
declare(strict_types=1);

namespace Domains\Construction\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Inspection
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $projectId,
        public string $subject,
    ) {
        if (trim($this->id) === '' || trim($this->projectId) === '' || trim($this->subject) === '') {
            throw new InvalidArgumentException('Invalid Construction inspection.');
        }
    }
}
