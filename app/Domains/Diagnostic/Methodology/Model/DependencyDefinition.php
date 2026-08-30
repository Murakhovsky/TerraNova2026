<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class DependencyDefinition
{
    public function __construct(
        public string $source,
        public string $target,
        public string $type,
        public float $strength = 1.0,
        public string $direction = 'positive',
    ) {
    }
}
