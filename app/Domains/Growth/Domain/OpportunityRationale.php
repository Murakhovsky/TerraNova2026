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

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'why_it_matters'=>$this->whyItMatters,
            'problem_hypothesis'=>$this->problemHypothesis,
            'why_now'=>$this->whyNow,
            'evidence_ids'=>$this->evidenceIds,
            'counter_evidence_ids'=>$this->counterEvidenceIds,
            'assumptions'=>$this->assumptions,
            'unknowns'=>$this->unknowns,
            'confidence'=>$this->confidence,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (string)($value['why_it_matters']??''),
            (string)($value['problem_hypothesis']??''),
            (string)($value['why_now']??''),
            self::strings($value['evidence_ids']??[]),
            self::strings($value['counter_evidence_ids']??[]),
            self::strings($value['assumptions']??[]),
            self::strings($value['unknowns']??[]),
            (float)($value['confidence']??0.0),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth rationale list is invalid.');
        $result=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth rationale list contains an invalid value.');
            $result[]=trim($item);
        }
        return $result;
    }
}
