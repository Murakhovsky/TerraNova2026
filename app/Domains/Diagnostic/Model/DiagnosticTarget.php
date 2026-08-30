<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use InvalidArgumentException;

final readonly class DiagnosticTarget
{
    public function __construct(
        public string $domain,
        public string $subjectType,
        public string $subjectId,
    ) {
        foreach (['domain' => $domain, 'subjectType' => $subjectType, 'subjectId' => $subjectId] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($field . ' must not be empty.');
            }
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $domain)) {
            throw new InvalidArgumentException('Diagnostic target domain has an invalid format.');
        }
    }

    /** @return array{domain:string, subject_type:string, subject_id:string} */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'subject_type' => $this->subjectType, 'subject_id' => $this->subjectId];
    }
}
