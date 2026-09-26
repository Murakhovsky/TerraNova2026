<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ResearchProposal
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $counterEvidenceIds
     * @param list<string> $assumptions
     * @param list<string> $unknowns
     */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $candidateId,
        public string $whyItMatters,
        public string $problemHypothesis,
        public string $whyNow,
        public array $evidenceIds,
        public array $counterEvidenceIds,
        public array $assumptions,
        public array $unknowns,
        public float $confidence,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $schemaVersion,
        public DateTimeImmutable $proposedAt,
    ) {
        foreach([
            'id'=>$id,'candidateId'=>$candidateId,'whyItMatters'=>$whyItMatters,'problemHypothesis'=>$problemHypothesis,
            'whyNow'=>$whyNow,'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth ResearchProposal '.$field.' is required.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth ResearchProposal requires supporting evidence.');
        foreach([$evidenceIds,$counterEvidenceIds,$assumptions,$unknowns] as $values){
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth ResearchProposal contains an invalid list value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth ResearchProposal confidence must be between 0 and 1.');
    }

    public function rationale(): OpportunityRationale
    {
        return new OpportunityRationale(
            $this->whyItMatters,$this->problemHypothesis,$this->whyNow,$this->evidenceIds,
            $this->counterEvidenceIds,$this->assumptions,$this->unknowns,$this->confidence,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'proposal_id'=>$this->id,'candidate_id'=>$this->candidateId,
            'why_it_matters'=>$this->whyItMatters,'problem_hypothesis'=>$this->problemHypothesis,'why_now'=>$this->whyNow,
            'evidence_ids'=>$this->evidenceIds,'counter_evidence_ids'=>$this->counterEvidenceIds,
            'assumptions'=>$this->assumptions,'unknowns'=>$this->unknowns,'confidence'=>$this->confidence,
            'provider'=>$this->provider,'model'=>$this->model,'prompt_version'=>$this->promptVersion,
            'schema_version'=>$this->schemaVersion,'proposed_at'=>$this->proposedAt->format(DATE_ATOM),
        ];
    }
}
