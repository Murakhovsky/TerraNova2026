<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\DTO\GrowthExecutionAction;

function expectGrowthV0220(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$action=new GrowthExecutionAction(
    'action-1','sales.send_message','deal','42','GROWTH','GERC-1',
    'APPROVAL_REQUIRED','MEDIUM','pending_approval','2026-09-23T12:00:00+00:00',null,
);
$view=$action->toArray();
expectGrowthV0220(($view['action_id']??null)==='action-1','Growth execution action lost action id.');
expectGrowthV0220(($view['type']??null)==='sales.send_message','Growth execution action lost canonical action type.');
expectGrowthV0220(($view['source_type']??null)==='GROWTH','Growth execution action must preserve Growth provenance.');
expectGrowthV0220(($view['status']??null)==='pending_approval','Growth execution action must expose governed action status.');

$root=dirname(__DIR__,2);
$service=(string)file_get_contents($root.'/app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
expectGrowthV0220(str_contains($service,"private const EXECUTABLE_ACTIONS=["),'Growth execution action vocabulary is missing.');
foreach(['send_email','connect_linkedin','offer_diagnostic','send_case_study','ask_introduction','invite_webinar'] as $actionType){
    expectGrowthV0220(str_contains($service,"'".$actionType."'"),'Growth execution message action missing: '.$actionType);
}
foreach(['monitor','ignore','create_report'] as $forbidden){
    expectGrowthV0220(!str_contains(
        substr($service,strpos($service,'private const EXECUTABLE_ACTIONS=['),500),
        "'".$forbidden."'"
    ),'Growth execution must keep '.$forbidden.' non-executable.');
}
expectGrowthV0220(str_contains($service,"count($deals)>1"),'Growth execution must reject ambiguous sales_deal bindings.');
expectGrowthV0220(str_contains($service,"count($deals)===1"),'Growth execution must preserve the post-handoff sales_deal branch.');
expectGrowthV0220(str_contains($service,'Post-handoff call execution belongs to Sales'),'Growth must not route post-handoff calls through sales.send_message.');
expectGrowthV0220(str_contains($service,"EngagementRecommendationStatus::Accepted"),'Growth execution must require accepted recommendation.');
expectGrowthV0220(str_contains($service,"'engagement_execution_payload'"),'Growth execution payload lock is missing.');
expectGrowthV0220(!str_contains($service,'ActionProposal'),'Growth application service must not know Kernel ActionProposal.');
expectGrowthV0220(!str_contains($service,'ActionPolicyService'),'Growth application service must not know Kernel ActionPolicyService.');

echo "Growth V0.22 Engagement Execution Bridge contracts passed.\n";
