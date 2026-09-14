<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Methodology\Validation;

final readonly class ValidationResult
{
    public bool $valid;

    /** @param list<ValidationIssue> $errors @param list<ValidationIssue> $warnings */
    public function __construct(public array $errors = [], public array $warnings = [])
    {
        $this->valid = $errors === [];
    }

    public function isValid(): bool
    {
        return $this->valid;
    }
}
