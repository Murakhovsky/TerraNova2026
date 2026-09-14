<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Result;

final readonly class Finding
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $ruleId,
        public string $criterionId,
        public string $statement,
        public string $severity,
        public array $evidenceIds = [],
    ) {
    }
}
