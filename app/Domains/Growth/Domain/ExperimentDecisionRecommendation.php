<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class ExperimentDecisionRecommendation
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $risks
     * @param list<string> $assumptions
     */
    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $experimentId,
        public readonly ExperimentDecisionType $decisionType,
        public readonly ?string $promotedVariantKey,
        public readonly string $rationale,
        public readonly array $evidenceIds,
        public readonly array $risks,
        public readonly array $assumptions,
        public readonly float $confidence,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $promptVersion,
        public readonly string $schemaVersion,
        public readonly DateTimeImmutable $createdAt,
        private ExperimentDecisionRecommendationStatus $status=ExperimentDecisionRecommendationStatus::Proposed,
        private ?string $decisionReason=null,
    ) {
        foreach([
            'id'=>$id,'experimentId'=>$experimentId,'rationale'=>$rationale,'provider'=>$provider,
            'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth ExperimentDecisionRecommendation '.$field.' is required.');
        }
        if($decisionType===ExperimentDecisionType::PromoteVariant){
            if($promotedVariantKey===null||trim($promotedVariantKey)===''){
                throw new InvalidArgumentException('Growth promote_variant recommendation requires promoted variant.');
            }
        }elseif($promotedVariantKey!==null){
            throw new InvalidArgumentException('Growth non-promote experiment recommendation must not select a variant.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth experiment decision recommendation requires evidence.');
        foreach([$evidenceIds,$risks,$assumptions] as $values){
            if(!array_is_list($values))throw new InvalidArgumentException('Growth experiment decision recommendation list is invalid.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)===''){
                    throw new InvalidArgumentException('Growth experiment decision recommendation list contains invalid value.');
                }
            }
        }
        if($confidence<0.0||$confidence>1.0){
            throw new InvalidArgumentException('Growth experiment decision recommendation confidence must be between 0 and 1.');
        }
        if($status===ExperimentDecisionRecommendationStatus::Proposed){
            if($decisionReason!==null)throw new InvalidArgumentException('Proposed Growth experiment decision cannot have decision reason.');
        }elseif($decisionReason===null||trim($decisionReason)===''){
            throw new InvalidArgumentException('Decided Growth experiment decision requires reason.');
        }
    }

    public function status():ExperimentDecisionRecommendationStatus{return $this->status;}
    public function decisionReason():?string{return $this->decisionReason;}

    public function accept(string $reason):void{$this->decide(ExperimentDecisionRecommendationStatus::Accepted,$reason);}
    public function dismiss(string $reason):void{$this->decide(ExperimentDecisionRecommendationStatus::Dismissed,$reason);}
    public function supersede(string $reason):void{$this->decide(ExperimentDecisionRecommendationStatus::Superseded,$reason);}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'recommendation_id'=>$this->id,
            'experiment_id'=>$this->experimentId,
            'decision_type'=>$this->decisionType->value,
            'promoted_variant_key'=>$this->promotedVariantKey,
            'rationale'=>$this->rationale,
            'evidence_ids'=>$this->evidenceIds,
            'risks'=>$this->risks,
            'assumptions'=>$this->assumptions,
            'confidence'=>$this->confidence,
            'provider'=>$this->provider,
            'model'=>$this->model,
            'prompt_version'=>$this->promptVersion,
            'schema_version'=>$this->schemaVersion,
            'status'=>$this->status->value,
            'decision_reason'=>$this->decisionReason,
            'created_at'=>$this->createdAt->format(DATE_ATOM),
        ];
    }

    private function decide(ExperimentDecisionRecommendationStatus $next,string $reason):void
    {
        if($this->status!==ExperimentDecisionRecommendationStatus::Proposed){
            throw new DomainException('Growth experiment decision recommendation is already decided.');
        }
        $reason=trim($reason);
        if($reason==='')throw new InvalidArgumentException('Growth experiment decision reason is required.');
        $this->status=$next;
        $this->decisionReason=$reason;
    }
}
