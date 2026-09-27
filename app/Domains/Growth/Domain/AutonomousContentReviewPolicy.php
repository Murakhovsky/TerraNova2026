<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use InvalidArgumentException;

final readonly class AutonomousContentReviewPolicy
{
    /** @var array<string,AutonomousContentReviewMode> */
    private array $channelModes;

    /** @param array<string,string|AutonomousContentReviewMode> $channelModes */
    public function __construct(
        array $channelModes,
        public float $minDraftConfidence,
        public int $maxBodyChars,
    ) {
        if($minDraftConfidence<0.0||$minDraftConfidence>1.0){
            throw new InvalidArgumentException('Autonomous content minimum draft confidence must be between 0 and 1.');
        }
        if($maxBodyChars<100||$maxBodyChars>10000){
            throw new InvalidArgumentException('Autonomous content maximum body length must be between 100 and 10000 characters.');
        }

        $normalized=[];
        foreach(['email','linkedin','phone'] as $channel){
            $raw=$channelModes[$channel]??null;
            $mode=$raw instanceof AutonomousContentReviewMode?$raw:(is_string($raw)?AutonomousContentReviewMode::tryFrom(strtolower(trim($raw))):null);
            if($mode===null)throw new InvalidArgumentException('Autonomous content review mode is required for '.$channel.'.');
            $normalized[$channel]=$mode;
        }
        $this->channelModes=$normalized;
    }

    public function modeFor(string $channel):AutonomousContentReviewMode
    {
        $channel=strtolower(trim($channel));
        return $this->channelModes[$channel]??AutonomousContentReviewMode::Blocked;
    }

    /** @param list<string> $riskFlags @return array<string,mixed> */
    public function evaluate(string $channel,float $confidence,array $riskFlags,string $body):array
    {
        $mode=$this->modeFor($channel);
        $length=mb_strlen($body);
        if($mode===AutonomousContentReviewMode::Blocked){
            return $this->decision($mode,false,false,'content_review_blocked','Generated content is blocked for this channel.',$confidence,$length,$riskFlags);
        }
        if($mode===AutonomousContentReviewMode::HumanReview){
            return $this->decision($mode,false,true,'human_review_required','Tenant policy requires human review of generated content.',$confidence,$length,$riskFlags);
        }
        if($confidence<$this->minDraftConfidence){
            return $this->decision($mode,false,true,'draft_confidence_below_threshold','Draft confidence is below the content auto-approval threshold.',$confidence,$length,$riskFlags);
        }
        if($riskFlags!==[]){
            return $this->decision($mode,false,true,'draft_risk_flags_require_review','Draft contains risk flags and requires human review.',$confidence,$length,$riskFlags);
        }
        if($length>$this->maxBodyChars){
            return $this->decision($mode,false,true,'draft_body_too_long','Draft body exceeds the content auto-approval length limit.',$confidence,$length,$riskFlags);
        }
        return $this->decision($mode,true,false,'policy_auto_approved','Draft satisfies the tenant content auto-approval policy.',$confidence,$length,$riskFlags);
    }

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'channel_modes'=>array_map(static fn(AutonomousContentReviewMode $mode):string=>$mode->value,$this->channelModes),
            'min_draft_confidence'=>$this->minDraftConfidence,
            'max_body_chars'=>$this->maxBodyChars,
        ];
    }

    /** @param list<string> $riskFlags @return array<string,mixed> */
    private function decision(
        AutonomousContentReviewMode $mode,bool $autoApprove,bool $humanReview,string $code,string $reason,
        float $confidence,int $bodyLength,array $riskFlags
    ):array {
        return [
            'mode'=>$mode->value,'auto_approve'=>$autoApprove,'requires_human_review'=>$humanReview,
            'code'=>$code,'reason'=>$reason,'confidence'=>$confidence,'body_length'=>$bodyLength,
            'risk_flags'=>$riskFlags,'min_draft_confidence'=>$this->minDraftConfidence,'max_body_chars'=>$this->maxBodyChars,
        ];
    }
}
