<?php
declare(strict_types=1);

namespace Platform\Search\Model;

use InvalidArgumentException;

final readonly class SearchHit
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public float $score,
        public ?string $title = null,
        public array $payload = [],
    ) {
        if (trim($this->id) === '' || trim($this->type) === '') {
            throw new InvalidArgumentException('Search hit requires id and type.');
        }
    }
}
