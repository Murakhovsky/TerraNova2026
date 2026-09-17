<?php
declare(strict_types=1);

namespace Platform\Knowledge\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Embedding
{
    /** @param list<float> $vector */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $chunkId,
        public string $model,
        public int $dimensions,
        public array $vector,
        public DateTimeImmutable $createdAt,
    ) {
        if (trim($this->id) === '' || trim($this->chunkId) === '' || trim($this->model) === '') {
            throw new InvalidArgumentException('Embedding requires id, chunk and model.');
        }
        if ($this->dimensions < 1 || count($this->vector) !== $this->dimensions) {
            throw new InvalidArgumentException('Embedding dimensions must match its vector.');
        }
    }
}
