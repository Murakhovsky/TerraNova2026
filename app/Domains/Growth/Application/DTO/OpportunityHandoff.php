<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DomainException;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;

final readonly class OpportunityHandoff
{
    /**
     * @param list<string> $signalIds
     * @param array<string, mixed> $scores
     * @param list<string> $evidenceIds
     * @param list<string> $unknowns
     */
    public function __construct(
        public string $candidateId,
        public string $organizationId,
        public string $opportunityType,
        public string $growthMode,
        public string $subjectType,
        public string $subjectId,
        public string $targetDomain,
        public array $signalIds,
        public string $whyItMatters,
        public string $problemHypothesis,
        public string $whyNow,
        public array $evidenceIds,
        public array $unknowns,
        public array $scores,
        public string $expectedValue,
        public string $recommendedPlay,
        public string $recommendedAction,
    ) {}

    public static function fromCandidate(OpportunityCandidate $candidate): self
    {
        if ($candidate->status() !== OpportunityCandidateStatus::ReadyForHandoff) {
            throw new DomainException('Growth handoff package requires a candidate ready for handoff.');
        }

        $rationale = $candidate->rationale();
        $score = $candidate->score();

        if ($rationale === null || $score === null) {
            throw new DomainException('Growth handoff package requires rationale and score.');
        }

        return new self(
            candidateId: $candidate->id,
            organizationId: $candidate->organizationId->value(),
            opportunityType: $candidate->type->value,
            growthMode: $candidate->mode->value,
            subjectType: $candidate->subjectType,
            subjectId: $candidate->subjectId,
            targetDomain: $candidate->targetDomain,
            signalIds: $candidate->signalIds(),
            whyItMatters: $rationale->whyItMatters,
            problemHypothesis: $rationale->problemHypothesis,
            whyNow: $rationale->whyNow,
            evidenceIds: $rationale->evidenceIds,
            unknowns: $rationale->unknowns,
            scores: $score->toArray(),
            expectedValue: (string) $candidate->expectedValue(),
            recommendedPlay: (string) $candidate->recommendedPlay(),
            recommendedAction: (string) $candidate->recommendedAction(),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'candidate_id'=>$this->candidateId,
            'organization_id'=>$this->organizationId,
            'opportunity_type'=>$this->opportunityType,
            'growth_mode'=>$this->growthMode,
            'subject_type'=>$this->subjectType,
            'subject_id'=>$this->subjectId,
            'target_domain'=>$this->targetDomain,
            'signal_ids'=>$this->signalIds,
            'why_it_matters'=>$this->whyItMatters,
            'problem_hypothesis'=>$this->problemHypothesis,
            'why_now'=>$this->whyNow,
            'evidence_ids'=>$this->evidenceIds,
            'unknowns'=>$this->unknowns,
            'scores'=>$this->scores,
            'expected_value'=>$this->expectedValue,
            'recommended_play'=>$this->recommendedPlay,
            'recommended_action'=>$this->recommendedAction,
        ];
    }
}
