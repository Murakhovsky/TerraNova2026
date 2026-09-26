<?php
declare(strict_types=1);

namespace Domains\Growth\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class OutreachSequenceStateMachine
{
    /** @return array<string,mixed> */
    public function evaluate(
        OutreachSequencePolicy $policy,
        string $channel,
        int $touchCount,
        bool $hasExecution,
        ?string $deliveryStatus,
        ?DateTimeImmutable $anchorAt,
        DateTimeImmutable $now,
        ?string $externalStopCode=null,
    ):array {
        if($touchCount<1)throw new InvalidArgumentException('Outreach sequence touch count must be positive.');

        if($externalStopCode!==null&&trim($externalStopCode)!==''){
            return ['action'=>'stop','code'=>trim($externalStopCode),'reason'=>'An authoritative stop signal was observed.','next_due_at'=>null];
        }
        if(!$policy->enabled){
            return ['action'=>'stop','code'=>'sequence_policy_disabled','reason'=>'Tenant sequence policy is disabled.','next_due_at'=>null];
        }
        if(!$policy->allowsChannel($channel)){
            return ['action'=>'stop','code'=>'sequence_channel_not_allowed','reason'=>'Sequence channel is no longer allowed.','next_due_at'=>null];
        }
        if(!$hasExecution){
            return ['action'=>'wait','code'=>'awaiting_execution','reason'=>'Current touch has not reached governed execution yet.','next_due_at'=>null];
        }

        $deliveryStatus=strtolower(trim((string)$deliveryStatus));
        if(in_array($channel,['linkedin','phone'],true)&&$deliveryStatus===''){
            return ['action'=>'wait','code'=>'delivery_observation_pending','reason'=>'Provider delivery feedback has not arrived yet.','next_due_at'=>null];
        }
        if(in_array($deliveryStatus,['failed','bounced','complained'],true)){
            return ['action'=>'stop','code'=>'delivery_'.$deliveryStatus,'reason'=>'Current touch delivery ended with '.$deliveryStatus.'.','next_due_at'=>null];
        }
        if($channel==='email'&&in_array($deliveryStatus,['queued','accepted'],true)){
            return ['action'=>'wait','code'=>'email_delivery_pending','reason'=>'Email provider has not reported sent/delivered or a terminal failure yet.','next_due_at'=>null];
        }
        if($channel==='phone'&&in_array($deliveryStatus,['accepted','started'],true)){
            return ['action'=>'wait','code'=>'phone_outcome_pending','reason'=>'Phone provider has not reported a terminal call outcome yet.','next_due_at'=>null];
        }
        if($channel==='phone'&&$deliveryStatus==='completed'){
            return ['action'=>'stop','code'=>'phone_completed','reason'=>'Phone conversation completed; autonomous follow-up must stop.','next_due_at'=>null];
        }
        if($anchorAt===null){
            return ['action'=>'wait','code'=>'execution_time_missing','reason'=>'Sequence cannot schedule without an execution or delivery timestamp.','next_due_at'=>null];
        }

        $anchorAt=$anchorAt->setTimezone(new DateTimeZone('UTC'));
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        $due=$anchorAt->modify('+'.$policy->followUpDelayHours.' hours');

        if($now<$due){
            return ['action'=>'wait','code'=>'follow_up_delay','reason'=>'Follow-up delay has not elapsed.','next_due_at'=>$due->format(DATE_ATOM)];
        }
        if($touchCount>=$policy->maxTouches){
            return ['action'=>'complete','code'=>'max_touches_reached','reason'=>'Sequence completed its configured touch budget.','next_due_at'=>null];
        }

        return ['action'=>'advance','code'=>'follow_up_due','reason'=>'Follow-up delay elapsed without a stop signal.','next_due_at'=>null];
    }
}
