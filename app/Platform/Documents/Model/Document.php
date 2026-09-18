<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Document
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $title,
    ) {
        if (trim($this->id) === '' || trim($this->title) === '') {
            throw new InvalidArgumentException('Invalid Documents document.');
        }
    }
}
