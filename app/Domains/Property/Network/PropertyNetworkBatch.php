<?php
declare(strict_types=1);

namespace Domains\Property\Network;

use InvalidArgumentException;

final readonly class PropertyNetworkBatch
{
    /** @param list<PropertyNetworkRecord> $records */
    public function __construct(
        public array $records,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
        public array $metadata = [],
    ) {
        foreach ($this->records as $record) {
            if (!$record instanceof PropertyNetworkRecord) {
                throw new InvalidArgumentException('Property network batch accepts only PropertyNetworkRecord instances.');
            }
        }
        if ($this->hasMore && $this->nextCursor === null) {
            throw new InvalidArgumentException('A paged network batch requires nextCursor.');
        }
    }
}
