<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domains\Growth\Application\Contract\GrowthAutonomousContentRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthAutonomousOutreachRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementActivationProviderInterface;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceGuardInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceRepositoryInterface;
use Domains\Growth\Domain\EngagementActivationMode;

final readonly class GrowthOutreachSequenceGuard implements GrowthOutreachSequenceGuardInterface
{
    private const STOP_OUTCOMES=['reply_received','qualified','disqualified','meeting_completed','won','lost'];

    public function __construct(
        private GrowthOutreachSequenceRepositoryInterface $sequences,
        private GrowthAutonomousOutreachRepositoryInterface $autonomy,
        private GrowthAutonomousContentRepositoryInterface $content,
        private GrowthEngagementActivationProviderInterface $activation,
        private GrowthLearningRepositoryInterface $learning,
        private GrowthEngagementExecutionRepositoryInterface $executions,
        private GrowthEngagementDeliveryRepositoryInterface $deliveries,
    ) {}

    public function bootstrapBlock(string $organizationId,array $recommendation,DateTimeImmutable $startedAt):?array
    {
        $startedAt=$startedAt->setTimezone(new DateTimeZone('UTC'));
        $channel=(string)($recommendation['channel']??'');
        $candidateId=(string)($recommendation['candidate_id']??'');

        $sequenceProfile=$this->sequences->latestProfile($organizationId);
        if($sequenceProfile===null||empty($sequenceProfile['enabled'])){
            return $this->block('sequence_policy_disabled','Tenant sequence policy is disabled.');
        }
        if(!in_array($channel,array_map('strval',$sequenceProfile['allowed_channels']??[]),true)){
            return $this->block('sequence_channel_not_allowed','Sequence channel is no longer allowed.');
        }

        $autonomy=$this->autonomy->latestProfile($organizationId);
        if($autonomy===null||empty($autonomy['enabled'])){
            return $this->block('autonomy_disabled','Tenant autonomous outreach is disabled.');
        }
        if(!in_array($channel,array_map('strval',$autonomy['allowed_channels']??[]),true)){
            return $this->block('autonomy_channel_not_allowed','Sequence channel is not allowed by autonomous outreach policy.');
        }
        if(!in_array('accepted',array_map('strval',$autonomy['allowed_statuses']??[]),true)){
            return $this->block('autonomy_accepted_status_not_allowed','Autonomous outreach policy does not allow accepted recommendations.');
        }
        if((float)($recommendation['confidence']??0)<(float)($autonomy['min_confidence']??1)){
            return $this->block('autonomy_confidence_below_threshold','Recommendation confidence is below the current autonomous outreach threshold.');
        }
        if((string)($recommendation['status']??'')!=='accepted'){
            return $this->block('sequence_recommendation_not_accepted','Sequence recommendations must remain accepted.');
        }
        if($this->activation->modeFor($organizationId,$channel)!==EngagementActivationMode::Auto){
            return $this->block('sequence_channel_not_auto','Sequence channel activation is no longer AUTO.');
        }

        $review=$this->content->latestReviewProfile($organizationId);
        if($review!==null){
            $field=match($channel){'email'=>'email_mode','linkedin'=>'linkedin_mode','phone'=>'phone_mode',default=>null};
            if($field===null||(string)($review[$field]??'blocked')==='blocked'){
                return $this->block('sequence_content_blocked','Generated outreach content is blocked on this channel.');
            }
        }

        if($candidateId!==''&&$this->learning->externalSubjectsForCandidate($organizationId,$candidateId,'sales','sales_deal')!==[]){
            return $this->block('sales_handoff','Candidate has been handed off to Sales.');
        }
        if($candidateId!==''){
            foreach($this->learning->outcomesForCandidate($organizationId,$candidateId,100) as $outcome){
                $type=(string)($outcome['outcome_type']??'');
                if(!in_array($type,self::STOP_OUTCOMES,true)||empty($outcome['observed_at']))continue;
                $observedAt=new DateTimeImmutable((string)$outcome['observed_at'],new DateTimeZone('UTC'));
                if($observedAt<$startedAt)continue;
                return $this->block('outcome_'.$type,'Growth learning observed '.$type.' after sequence start.');
            }
        }

        return null;
    }

    public function hardBlockForRecommendation(string $organizationId,array $recommendation):?array
    {
        $recommendationId=(string)($recommendation['recommendation_id']??'');
        if($recommendationId==='')return $this->block('sequence_recommendation_invalid','Sequence recommendation id is missing.');

        $sequence=$this->sequences->sequenceForRecommendation($organizationId,$recommendationId);
        if($sequence===null)return null;
        if((string)$sequence['status']!=='active'){
            return $this->block('sequence_not_active','Outreach sequence is no longer active.');
        }

        $channel=(string)($recommendation['channel']??$sequence['channel']??'');
        $profile=$this->sequences->latestProfile($organizationId);
        if($profile===null||empty($profile['enabled'])){
            return $this->block('sequence_policy_disabled','Tenant sequence policy is disabled.');
        }
        if(!in_array($channel,array_map('strval',$profile['allowed_channels']??[]),true)){
            return $this->block('sequence_channel_not_allowed','Sequence channel is no longer allowed.');
        }
        if((int)$sequence['touch_count']>(int)($profile['max_touches']??0)){
            return $this->block('sequence_touch_budget_reduced','Current tenant policy reduced the sequence touch budget below this touch.');
        }

        $candidateId=(string)($recommendation['candidate_id']??$sequence['candidate_id']??'');
        if($candidateId!==''&&$this->learning->externalSubjectsForCandidate($organizationId,$candidateId,'sales','sales_deal')!==[]){
            return $this->block('sales_handoff','Candidate has been handed off to Sales.');
        }
        if($candidateId!==''){
            $startedAt=new DateTimeImmutable((string)$sequence['started_at'],new DateTimeZone('UTC'));
            foreach($this->learning->outcomesForCandidate($organizationId,$candidateId,100) as $outcome){
                $type=(string)($outcome['outcome_type']??'');
                if(!in_array($type,self::STOP_OUTCOMES,true)||empty($outcome['observed_at']))continue;
                $observedAt=new DateTimeImmutable((string)$outcome['observed_at']);
                if($observedAt<$startedAt)continue;
                return $this->block('outcome_'.$type,'Growth learning observed '.$type.' after sequence start.');
            }
        }

        foreach($this->sequences->steps($organizationId,(string)$sequence['sequence_id']) as $step){
            $execution=$this->executions->byRecommendation($organizationId,(string)$step['recommendation_id']);
            if($execution===null)continue;
            $delivery=$this->deliveries->latestForExecution($organizationId,(string)$execution['execution_id']);
            if($delivery===null)continue;
            $status=strtolower((string)($delivery['status']??''));
            if($status==='failed')return $this->block('delivery_failed','A sequence touch delivery failed.');
            if((string)$sequence['channel']==='phone'&&$status==='completed'){
                return $this->block('phone_completed','A phone conversation completed; follow-up must stop.');
            }
        }

        return null;
    }

    public function blockingForRecommendation(string $organizationId,array $recommendation):?array
    {
        $hardBlock=$this->hardBlockForRecommendation($organizationId,$recommendation);
        if($hardBlock!==null)return $hardBlock;

        $recommendationId=(string)($recommendation['recommendation_id']??'');
        $sequence=$this->sequences->sequenceForRecommendation($organizationId,$recommendationId);
        if($sequence===null)return null;

        return $this->bootstrapBlock(
            $organizationId,$recommendation,new DateTimeImmutable((string)$sequence['started_at'],new DateTimeZone('UTC'))
        );
    }

    /** @return array{code:string,reason:string} */
    private function block(string $code,string $reason):array{return ['code'=>$code,'reason'=>$reason];}
}
