<?php
declare(strict_types=1);

namespace Platform\Documents\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class File
{
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $storageKey,
        public string $mimeType,
    ) {
        if (trim($this->id) === '' || trim($this->storageKey) === '' || trim($this->mimeType) === '') {
            throw new InvalidArgumentException('Invalid Documents file.');
        }
    }
}
