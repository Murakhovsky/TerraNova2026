<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Chunk
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $documentId,
        public int $sequence,
        public string $content,
        public int $tokenCount,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->documentId) === '' || trim($this->content) === '') {
            throw new InvalidArgumentException('Knowledge chunk requires id, document and content.');
        }
        if ($this->sequence < 0 || $this->tokenCount < 1) {
            throw new InvalidArgumentException('Knowledge chunk sequence/token count is invalid.');
        }
    }
}
