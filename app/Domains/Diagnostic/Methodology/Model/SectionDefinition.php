<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class SectionDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public float $weight = 1.0,
    ) {
    }
}
