<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class LearningOptimizationRecommendation
{
    /**
     * @param array<string,mixed> $proposedCriteria
     * @param list<string> $evidenceIds
     * @param list<string> $risks
     * @param list<string> $assumptions
     */
    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly OptimizationTargetType $targetType,
        public readonly string $targetId,
        public readonly int $baseRevision,
        public readonly string $proposedName,
        public readonly array $proposedCriteria,
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
        private OptimizationRecommendationStatus $status=OptimizationRecommendationStatus::Proposed,
        private ?string $decisionReason=null,
        private ?int $materializedRevision=null,
    ) {
        foreach([
            'id'=>$id,'targetId'=>$targetId,'proposedName'=>$proposedName,'rationale'=>$rationale,
            'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion,
        ] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth LearningOptimizationRecommendation '.$field.' is required.');
        }
        if($baseRevision<1)throw new InvalidArgumentException('Growth optimization base revision must be positive.');
        if($evidenceIds===[])throw new InvalidArgumentException('Growth optimization recommendation requires evidence.');
        foreach([$evidenceIds,$risks,$assumptions] as $values){
            if(!array_is_list($values))throw new InvalidArgumentException('Growth optimization recommendation list field is invalid.');
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth optimization recommendation list contains invalid value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth optimization recommendation confidence must be between 0 and 1.');
        self::validateCriteria($targetType,$proposedCriteria);

        if($status===OptimizationRecommendationStatus::Proposed){
            if($decisionReason!==null||$materializedRevision!==null){
                throw new InvalidArgumentException('Proposed Growth optimization recommendation cannot have decision metadata.');
            }
        }else{
            if($decisionReason===null||trim($decisionReason)===''){
                throw new InvalidArgumentException('Decided Growth optimization recommendation requires reason.');
            }
            if($status===OptimizationRecommendationStatus::Materialized){
                if($materializedRevision!==$baseRevision+1){
                    throw new InvalidArgumentException('Materialized Growth optimization revision must equal baseRevision + 1.');
                }
            }elseif($materializedRevision!==null){
                throw new InvalidArgumentException('Non-materialized Growth optimization recommendation cannot expose materialized revision.');
            }
        }
    }

    public function status():OptimizationRecommendationStatus{return $this->status;}
    public function decisionReason():?string{return $this->decisionReason;}
    public function materializedRevision():?int{return $this->materializedRevision;}

    public function accept(string $reason):void
    {
        $this->decide(OptimizationRecommendationStatus::Accepted,$reason);
    }

    public function dismiss(string $reason):void
    {
        $this->decide(OptimizationRecommendationStatus::Dismissed,$reason);
    }

    public function supersede(string $reason):void
    {
        $this->decide(OptimizationRecommendationStatus::Superseded,$reason);
    }

    public function markStale(string $reason):void
    {
        if(!in_array($this->status,[OptimizationRecommendationStatus::Proposed,OptimizationRecommendationStatus::Accepted],true)){
            throw new DomainException('Only proposed or accepted Growth optimization recommendation can become stale.');
        }
        $this->status=OptimizationRecommendationStatus::Stale;
        $this->decisionReason=self::reason($reason);
        $this->materializedRevision=null;
    }

    public function materialize(int $revision,string $reason):void
    {
        if($this->status!==OptimizationRecommendationStatus::Accepted){
            throw new DomainException('Only accepted Growth optimization recommendation can be materialized.');
        }
        if($revision!==$this->baseRevision+1){
            throw new InvalidArgumentException('Growth optimization materialized revision must equal baseRevision + 1.');
        }
        $this->status=OptimizationRecommendationStatus::Materialized;
        $this->decisionReason=self::reason($reason);
        $this->materializedRevision=$revision;
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'recommendation_id'=>$this->id,
            'target_type'=>$this->targetType->value,
            'target_id'=>$this->targetId,
            'base_revision'=>$this->baseRevision,
            'proposed_name'=>$this->proposedName,
            'proposed_criteria'=>$this->proposedCriteria,
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
            'materialized_revision'=>$this->materializedRevision,
            'created_at'=>$this->createdAt->format(DATE_ATOM),
        ];
    }

    private function decide(OptimizationRecommendationStatus $next,string $reason):void
    {
        if($this->status!==OptimizationRecommendationStatus::Proposed){
            throw new DomainException('Growth optimization recommendation is already decided.');
        }
        $this->status=$next;
        $this->decisionReason=self::reason($reason);
    }

    private static function reason(string $reason):string
    {
        $reason=trim($reason);
        if($reason==='')throw new InvalidArgumentException('Growth optimization decision reason is required.');
        return $reason;
    }

    /** @param array<string,mixed> $criteria */
    private static function validateCriteria(OptimizationTargetType $targetType,array $criteria):void
    {
        match($targetType){
            OptimizationTargetType::IcpProfile=>IcpCriteria::fromArray($criteria),
            OptimizationTargetType::QualificationPolicy=>QualificationPolicyCriteria::fromArray($criteria),
        };
    }
}
