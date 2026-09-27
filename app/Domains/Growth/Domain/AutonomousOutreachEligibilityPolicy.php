<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class AutonomousOutreachEligibilityPolicy
{
    /** @var list<string> */
    public array $allowedChannels;
    /** @var list<string> */
    public array $allowedStatuses;

    /** @param list<string> $allowedChannels @param list<string> $allowedStatuses */
    public function __construct(
        public bool $enabled,
        public float $minConfidence,
        array $allowedChannels,
        array $allowedStatuses,
        public int $maxActionsPerRun,
    ) {
        if($minConfidence<0.0||$minConfidence>1.0){
            throw new InvalidArgumentException('Autonomous outreach minimum confidence must be between 0 and 1.');
        }
        if($maxActionsPerRun<1||$maxActionsPerRun>100){
            throw new InvalidArgumentException('Autonomous outreach max actions per run must be between 1 and 100.');
        }

        $channels=[];
        foreach($allowedChannels as $channel){
            if(!is_string($channel))throw new InvalidArgumentException('Autonomous outreach channel is invalid.');
            $channel=strtolower(trim($channel));
            $value=EngagementChannel::tryFrom($channel);
            if($value===null||!in_array($value,[EngagementChannel::Email,EngagementChannel::LinkedIn,EngagementChannel::Phone],true)){
                throw new InvalidArgumentException('Autonomous outreach channel is unsupported.');
            }
            $channels[$channel]=true;
        }
        if($channels===[])throw new InvalidArgumentException('Autonomous outreach requires at least one allowed channel.');
        $normalizedChannels=array_keys($channels);
        sort($normalizedChannels,SORT_STRING);
        $this->allowedChannels=$normalizedChannels;

        $statuses=[];
        foreach($allowedStatuses as $status){
            if(!is_string($status))throw new InvalidArgumentException('Autonomous outreach recommendation status is invalid.');
            $status=strtolower(trim($status));
            if(!in_array($status,[EngagementRecommendationStatus::Proposed->value,EngagementRecommendationStatus::Accepted->value],true)){
                throw new InvalidArgumentException('Autonomous outreach supports only proposed or accepted recommendations.');
            }
            $statuses[$status]=true;
        }
        if($statuses===[])throw new InvalidArgumentException('Autonomous outreach requires at least one allowed recommendation status.');
        $normalizedStatuses=array_keys($statuses);
        sort($normalizedStatuses,SORT_STRING);
        $this->allowedStatuses=$normalizedStatuses;
    }

    /** @param array<string,mixed> $recommendation @return array<string,mixed> */
    public function evaluate(array $recommendation,bool $hasStagedPayload,EngagementActivationMode $activationMode):array
    {
        $channel=strtolower(trim((string)($recommendation['channel']??'')));
        $status=strtolower(trim((string)($recommendation['status']??'')));
        $confidence=(float)($recommendation['confidence']??0.0);

        if(!$this->enabled)return $this->decision(false,'autonomy_disabled','Tenant autonomous outreach is disabled.',$channel,$status,$confidence);
        if(!$hasStagedPayload)return $this->decision(false,'staged_payload_required','Autonomous outreach requires a staged execution payload.',$channel,$status,$confidence);
        if(!in_array($status,$this->allowedStatuses,true))return $this->decision(false,'recommendation_status_not_allowed','Recommendation status is not allowed by the autonomy policy.',$channel,$status,$confidence);
        if($confidence<$this->minConfidence)return $this->decision(false,'confidence_below_autonomy_threshold','Recommendation confidence is below the tenant autonomy threshold.',$channel,$status,$confidence);
        if(!in_array($channel,$this->allowedChannels,true))return $this->decision(false,'channel_not_allowed_for_autonomy','Recommendation channel is not allowed by the autonomy policy.',$channel,$status,$confidence);
        if($activationMode!==EngagementActivationMode::Auto)return $this->decision(false,'channel_not_auto','Autonomous initiation requires channel activation mode AUTO.',$channel,$status,$confidence);

        return $this->decision(true,$status===EngagementRecommendationStatus::Proposed->value?'eligible_with_auto_accept':'eligible','Recommendation is eligible for controlled autonomous initiation.',$channel,$status,$confidence);
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'enabled'=>$this->enabled,
            'min_confidence'=>$this->minConfidence,
            'allowed_channels'=>$this->allowedChannels,
            'allowed_statuses'=>$this->allowedStatuses,
            'max_actions_per_run'=>$this->maxActionsPerRun,
        ];
    }

    /** @return array<string,mixed> */
    private function decision(bool $allowed,string $code,string $reason,string $channel,string $status,float $confidence):array
    {
        return [
            'allowed'=>$allowed,'code'=>$code,'reason'=>$reason,
            'channel'=>$channel,'status'=>$status,'confidence'=>$confidence,
            'min_confidence'=>$this->minConfidence,
        ];
    }
}
