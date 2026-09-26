<?php

declare(strict_types=1);

namespace App\Application\Property\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class RecordPublicPropertyViewCommand implements CommandInterface
{
    /** @param array<string,string> $context */
    public function __construct(
        public int $propertyId,
        public array $context = [],
    ) {
    }
}
