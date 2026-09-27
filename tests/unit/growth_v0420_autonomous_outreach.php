<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Domain\AutonomousOutreachEligibilityPolicy;
use Domains\Growth\Domain\AutonomousOutreachDeferred;
use Domains\Growth\Domain\EngagementActivationMode;

function expectGrowthV0420(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$safe=new AutonomousOutreachEligibilityPolicy(false,0.90,['email'],['accepted'],5);
$recommendation=['channel'=>'email','status'=>'accepted','confidence'=>0.95];
expectGrowthV0420(($safe->evaluate($recommendation,true,EngagementActivationMode::Auto)['code']??null)==='autonomy_disabled','Safe default must disable autonomous outreach.');

$policy=new AutonomousOutreachEligibilityPolicy(true,0.90,['email','linkedin'],['accepted','proposed'],10);
expectGrowthV0420(($policy->evaluate($recommendation,false,EngagementActivationMode::Auto)['code']??null)==='staged_payload_required','Staged payload must be mandatory.');
expectGrowthV0420(($policy->evaluate(['channel'=>'email','status'=>'accepted','confidence'=>0.70],true,EngagementActivationMode::Auto)['code']??null)==='confidence_below_autonomy_threshold','Confidence threshold must be enforced.');
expectGrowthV0420(($policy->evaluate(['channel'=>'phone','status'=>'accepted','confidence'=>0.99],true,EngagementActivationMode::Auto)['code']??null)==='channel_not_allowed_for_autonomy','Channel allowlist must be enforced.');
expectGrowthV0420(($policy->evaluate($recommendation,true,EngagementActivationMode::ApprovalRequired)['code']??null)==='channel_not_auto','Autonomy must require channel AUTO mode.');
expectGrowthV0420(($policy->evaluate($recommendation,true,EngagementActivationMode::Auto)['allowed']??false)===true,'Eligible accepted recommendation must pass.');
expectGrowthV0420(($policy->evaluate(['channel'=>'email','status'=>'proposed','confidence'=>0.95],true,EngagementActivationMode::Auto)['code']??null)==='eligible_with_auto_accept','Explicitly allowed proposed recommendation must use auto-accept path.');

$deferred=new AutonomousOutreachDeferred('autonomy_pre_handoff_only','Pre-handoff only.',['code'=>'eligible_post_handoff','can_propose'=>true]);
expectGrowthV0420(($deferred->eligibility['code']??null)==='autonomy_pre_handoff_only','Deferred autonomy result must expose the overriding failure code.');
expectGrowthV0420(($deferred->eligibility['can_propose']??true)===false,'Deferred autonomy result must not remain executable.');

echo "Growth V0.42 Autonomous Outreach eligibility contracts passed.\n";
