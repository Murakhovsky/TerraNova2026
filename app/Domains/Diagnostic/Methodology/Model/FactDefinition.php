<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class FactDefinition
{
    /** @param list<string> $enumValues */
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public ?string $unit = null,
        public array $enumValues = [],
    ) {
    }
}
