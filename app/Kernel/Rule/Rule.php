<?php
declare(strict_types=1);

namespace Kernel\Rule;

final readonly class Rule
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public string $trigger,
        public array $conditions,
        public array $effect,
        public int $version = 1,
        public int $priority = 100,
    ) {
    }
}
