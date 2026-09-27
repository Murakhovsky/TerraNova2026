<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QualificationEvaluation
{
    public const MODEL_VERSION='growth-qualification-v1';

    /** @param list<string> $failedCriteria */
    public function __construct(
        public string $candidateId,
        public string $policyId,
        public int $policyRevision,
        public OpportunityRationale $rationale,
        public OpportunityScore $score,
        public QualificationOutcome $outcome,
        public array $failedCriteria,
        public string $reason,
        public DateTimeImmutable $evaluatedAt,
    ) {
        if(trim($candidateId)===''||trim($policyId)===''||$policyRevision<1||trim($reason)===''){
            throw new InvalidArgumentException('Growth QualificationEvaluation is incomplete.');
        }
        foreach($failedCriteria as $criterion){
            if(!is_string($criterion)||trim($criterion)==='')throw new InvalidArgumentException('Growth failed qualification criterion is invalid.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'candidate_id'=>$this->candidateId,
            'policy_id'=>$this->policyId,
            'policy_revision'=>$this->policyRevision,
            'rationale'=>$this->rationale->toArray(),
            'score'=>$this->score->toArray(),
            'outcome'=>$this->outcome->value,
            'failed_criteria'=>$this->failedCriteria,
            'reason'=>$this->reason,
            'model_version'=>self::MODEL_VERSION,
            'evaluated_at'=>$this->evaluatedAt->format(DATE_ATOM),
        ];
    }
}
