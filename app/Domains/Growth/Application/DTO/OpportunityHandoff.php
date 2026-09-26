<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

use DomainException;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use InvalidArgumentException;

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
    ) {
        foreach([
            'candidateId'=>$candidateId,'organizationId'=>$organizationId,'opportunityType'=>$opportunityType,
            'growthMode'=>$growthMode,'subjectType'=>$subjectType,'subjectId'=>$subjectId,'targetDomain'=>$targetDomain,
            'whyItMatters'=>$whyItMatters,'problemHypothesis'=>$problemHypothesis,'whyNow'=>$whyNow,
            'expectedValue'=>$expectedValue,'recommendedPlay'=>$recommendedPlay,'recommendedAction'=>$recommendedAction,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth OpportunityHandoff '.$field.' is required.');
        }
        if($signalIds===[]||$evidenceIds===[]||$scores===[]){
            throw new InvalidArgumentException('Growth OpportunityHandoff requires signals, evidence and scores.');
        }
        foreach([$signalIds,$evidenceIds,$unknowns] as $values){
            if(!array_is_list($values))throw new InvalidArgumentException('Growth OpportunityHandoff list field is invalid.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth OpportunityHandoff list value is invalid.');
            }
        }
        if(array_is_list($scores))throw new InvalidArgumentException('Growth OpportunityHandoff scores must be an object.');
    }

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

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $strings=static function(mixed $value,string $field): string {
            if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth OpportunityHandoff '.$field.' is invalid.');
            return trim($value);
        };
        $list=static function(mixed $value,string $field): array {
            if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth OpportunityHandoff '.$field.' must be a list.');
            $out=[];
            foreach($value as $item){
                if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth OpportunityHandoff '.$field.' contains invalid value.');
                $out[]=trim($item);
            }
            return $out;
        };
        $scores=$data['scores']??null;
        if(!is_array($scores)||$scores===[]||array_is_list($scores))throw new InvalidArgumentException('Growth OpportunityHandoff scores are invalid.');

        return new self(
            $strings($data['candidate_id']??null,'candidate_id'),
            $strings($data['organization_id']??null,'organization_id'),
            $strings($data['opportunity_type']??null,'opportunity_type'),
            $strings($data['growth_mode']??null,'growth_mode'),
            $strings($data['subject_type']??null,'subject_type'),
            $strings($data['subject_id']??null,'subject_id'),
            $strings($data['target_domain']??null,'target_domain'),
            $list($data['signal_ids']??null,'signal_ids'),
            $strings($data['why_it_matters']??null,'why_it_matters'),
            $strings($data['problem_hypothesis']??null,'problem_hypothesis'),
            $strings($data['why_now']??null,'why_now'),
            $list($data['evidence_ids']??null,'evidence_ids'),
            $list($data['unknowns']??[],'unknowns'),
            $scores,
            $strings($data['expected_value']??null,'expected_value'),
            $strings($data['recommended_play']??null,'recommended_play'),
            $strings($data['recommended_action']??null,'recommended_action'),
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
