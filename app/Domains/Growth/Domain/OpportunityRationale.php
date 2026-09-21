<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class OpportunityRationale
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $counterEvidenceIds
     * @param list<string> $assumptions
     * @param list<string> $unknowns
     */
    public function __construct(
        public string $whyItMatters,
        public string $problemHypothesis,
        public string $whyNow,
        public array $evidenceIds,
        public array $counterEvidenceIds = [],
        public array $assumptions = [],
        public array $unknowns = [],
        public float $confidence = 0.0,
    ) {
        foreach ([
            'whyItMatters' => $whyItMatters,
            'problemHypothesis' => $problemHypothesis,
            'whyNow' => $whyNow,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Growth rationale %s is required.', $field));
            }
        }

        if ($evidenceIds === []) {
            throw new InvalidArgumentException('Growth rationale requires evidence.');
        }

        foreach ([$evidenceIds, $counterEvidenceIds] as $references) {
            foreach ($references as $reference) {
                if (trim($reference) === '') {
                    throw new InvalidArgumentException('Growth rationale evidence reference must not be empty.');
                }
            }
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Growth rationale confidence must be between 0 and 1.');
        }
    }
}
