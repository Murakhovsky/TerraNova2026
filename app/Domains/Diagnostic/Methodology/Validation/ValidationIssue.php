<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Validation;

final readonly class ValidationIssue
{
    public function __construct(
        public string $code,
        public string $path,
        public string $message,
    ) {
    }
}
