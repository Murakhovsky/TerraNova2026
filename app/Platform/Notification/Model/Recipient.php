<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

use InvalidArgumentException;

final readonly class Recipient
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $address,
        public ?string $name = null,
        public ?string $userId = null,
        public array $metadata = [],
    ) {
        if (trim($this->address) === '') {
            throw new InvalidArgumentException('Notification recipient requires an address.');
        }
    }
}
