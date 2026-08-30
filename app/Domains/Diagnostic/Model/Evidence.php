<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Evidence
{
    public function __construct(
        public string $id,
        public EvidenceType $type,
        public string $title,
        public string $source,
        public DateTimeImmutable $capturedAt,
        public array $metadata = [],
    ) {
        if (trim($id) === '' || trim($title) === '' || trim($source) === '') {
            throw new InvalidArgumentException('Evidence id, title and source must not be empty.');
        }
    }
}
