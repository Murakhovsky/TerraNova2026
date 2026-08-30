<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DiagnosticRecord
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $upstreamRecordIds
     */
    public function __construct(
        public string $id,
        public DiagnosticRecordType $type,
        public string $criterionCode,
        public string $statement,
        public string|int|float|bool|null $value,
        public ?string $unit,
        public array $evidenceIds,
        public array $upstreamRecordIds,
        public DateTimeImmutable $recordedAt,
    ) {
        if (trim($id) === '' || trim($criterionCode) === '' || trim($statement) === '') {
            throw new InvalidArgumentException('Diagnostic record id, criterion and statement must not be empty.');
        }
        if ($type === DiagnosticRecordType::Metric && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('A metric value must be numeric.');
        }
        if ($type->requiresEvidenceOrUpstream() && $evidenceIds === [] && $upstreamRecordIds === []) {
            throw new InvalidArgumentException($type->value . ' must reference evidence or an upstream record.');
        }
        if (count(array_unique($evidenceIds)) !== count($evidenceIds)
            || count(array_unique($upstreamRecordIds)) !== count($upstreamRecordIds)
        ) {
            throw new InvalidArgumentException('Traceability references must be unique.');
        }
    }
}
