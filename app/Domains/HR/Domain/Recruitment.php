<?php
declare(strict_types=1);

namespace Domains\HR\Domain;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Recruitment
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $candidateId,
        public string $positionId,
    ) {
        if (trim($this->id) === '' || trim($this->candidateId) === '' || trim($this->positionId) === '') {
            throw new InvalidArgumentException('Invalid HR recruitment.');
        }
    }
}
