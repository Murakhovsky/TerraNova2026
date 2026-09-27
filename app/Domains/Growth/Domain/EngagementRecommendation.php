<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final class EngagementRecommendation
{
    /**
     * @param list<string> $evidenceIds
     * @param list<string> $unknowns
     */
    public function __construct(
        public readonly string $id,
        public readonly OrganizationId $organizationId,
        public readonly string $candidateId,
        public readonly NextBestActionType $actionType,
        public readonly EngagementChannel $channel,
        public readonly ?string $contactId,
        public readonly string $rationale,
        public readonly string $messageAngle,
        public readonly array $evidenceIds,
        public readonly array $unknowns,
        public readonly float $confidence,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $promptVersion,
        public readonly string $schemaVersion,
        public readonly DateTimeImmutable $createdAt,
        private EngagementRecommendationStatus $status=EngagementRecommendationStatus::Proposed,
        private ?string $decisionReason=null,
    ) {
        foreach(['id'=>$id,'candidateId'=>$candidateId,'rationale'=>$rationale,'messageAngle'=>$messageAngle,'provider'=>$provider,'model'=>$model,'promptVersion'=>$promptVersion,'schemaVersion'=>$schemaVersion] as $field=>$value){
            if(trim($value)==='')throw new InvalidArgumentException('Growth EngagementRecommendation '.$field.' is required.');
        }
        if($contactId!==null&&trim($contactId)==='')throw new InvalidArgumentException('Growth EngagementRecommendation contactId is invalid.');
        if(!$actionType->allowsChannel($channel))throw new InvalidArgumentException('Growth EngagementRecommendation action/channel combination is invalid.');
        if(in_array($actionType,[NextBestActionType::Ignore,NextBestActionType::Monitor,NextBestActionType::CreateReport],true)&&$contactId!==null){
            throw new InvalidArgumentException('Growth EngagementRecommendation non-contact action must not select a contact.');
        }
        if(in_array($actionType,[NextBestActionType::ConnectLinkedIn,NextBestActionType::SendEmail,NextBestActionType::Call],true)&&$contactId===null){
            throw new InvalidArgumentException('Growth EngagementRecommendation direct-contact action requires contactId.');
        }
        if($status===EngagementRecommendationStatus::Proposed&&$decisionReason!==null){
            throw new InvalidArgumentException('Proposed Growth EngagementRecommendation must not have decision reason.');
        }
        if($status!==EngagementRecommendationStatus::Proposed&&($decisionReason===null||trim($decisionReason)==='')){
            throw new InvalidArgumentException('Decided Growth EngagementRecommendation requires decision reason.');
        }
        if($evidenceIds===[])throw new InvalidArgumentException('Growth EngagementRecommendation requires evidence.');
        foreach([$evidenceIds,$unknowns] as $values){
            foreach($values as $value){
                if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth EngagementRecommendation contains an invalid list value.');
            }
        }
        if($confidence<0.0||$confidence>1.0)throw new InvalidArgumentException('Growth EngagementRecommendation confidence must be between 0 and 1.');
    }

    public function status():EngagementRecommendationStatus{return $this->status;}
    public function decisionReason():?string{return $this->decisionReason;}

    public function accept(string $reason):void
    {
        $this->decide(EngagementRecommendationStatus::Accepted,$reason);
    }

    public function dismiss(string $reason):void
    {
        $this->decide(EngagementRecommendationStatus::Dismissed,$reason);
    }

    public function supersede(string $reason):void
    {
        $this->decide(EngagementRecommendationStatus::Superseded,$reason);
    }

    private function decide(EngagementRecommendationStatus $next,string $reason):void
    {
        if($this->status!==EngagementRecommendationStatus::Proposed){
            throw new DomainException('Growth engagement recommendation is already decided.');
        }
        $reason=trim($reason);
        if($reason==='')throw new InvalidArgumentException('Growth engagement decision reason is required.');
        $this->status=$next;
        $this->decisionReason=$reason;
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'recommendation_id'=>$this->id,'candidate_id'=>$this->candidateId,
            'action_type'=>$this->actionType->value,'channel'=>$this->channel->value,'contact_id'=>$this->contactId,
            'rationale'=>$this->rationale,'message_angle'=>$this->messageAngle,'evidence_ids'=>$this->evidenceIds,
            'unknowns'=>$this->unknowns,'confidence'=>$this->confidence,'provider'=>$this->provider,'model'=>$this->model,
            'prompt_version'=>$this->promptVersion,'schema_version'=>$this->schemaVersion,
            'status'=>$this->status->value,'decision_reason'=>$this->decisionReason,'created_at'=>$this->createdAt->format(DATE_ATOM),
        ];
    }
}
