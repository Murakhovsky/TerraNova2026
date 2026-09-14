<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Model;

final readonly class RuleDefinition
{
    public function __construct(
        public string $id,
        public string $criterionId,
        public array $conditions,
        public string $finding,
        public string $severity,
    ) {
    }
}
