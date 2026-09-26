<?php
declare(strict_types=1);

function expectGrowthV0470Architecture(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>file_get_contents($root.'/'.$path)?:'';
$module=require $root.'/app/Domains/Growth/module.php';

expectGrowthV0470Architecture(version_compare((string)$module['version'],'0.47.0','>='),'Growth module version must be >= 0.47.0.');
foreach(['growth.engagement.email_delivery_feedback','growth.engagement.email_complaint_suppression','growth.engagement.email_conversation_feedback'] as $capability){
    expectGrowthV0470Architecture(in_array($capability,$module['contributions']['capabilities']??[],true),'V0.47 capability missing: '.$capability);
}
expectGrowthV0470Architecture(
    in_array('app/migrations/20260926_000112_growth_v0470_email_delivery_parity.sql',$module['contributions']['migration_files']??[],true),
    'V0.47 migration missing.'
);

$statusSource=$read('app/Domains/Growth/Domain/EngagementDeliveryStatus.php');
foreach(["case Queued='queued'","case Bounced='bounced'","case Complained='complained'","EngagementChannel::Email"] as $needle){
    expectGrowthV0470Architecture(str_contains($statusSource,$needle),'Email lifecycle status invariant missing: '.$needle);
}

$delivery=$read('app/Domains/Growth/Application/Service/GrowthEngagementDeliveryService.php');
foreach(["EngagementChannel::Email=>'growth.send_message'",'EngagementDeliveryStatus::Complained','suppressContact'] as $needle){
    expectGrowthV0470Architecture(str_contains($delivery,$needle),'Email delivery service invariant missing: '.$needle);
}

$guard=$read('app/Domains/Growth/Application/Service/GrowthOutreachSequenceGuard.php');
foreach(["['failed','bounced','complained']","'delivery_'.$status"] as $needle){
    expectGrowthV0470Architecture(str_contains($guard,$needle),'Email failure guard invariant missing: '.$needle);
}

$machine=$read('app/Domains/Growth/Domain/OutreachSequenceStateMachine.php');
expectGrowthV0470Architecture(str_contains($machine,'email_delivery_pending'),'Email sequence must understand pending provider delivery.');
expectGrowthV0470Architecture(str_contains($machine,"['failed','bounced','complained']"),'Email terminal failure states must stop sequence.');

$webhook=$read('symfony/src/Application/Growth/Integration/GrowthEngagementDeliveryWebhook.php');
expectGrowthV0470Architecture(str_contains($webhook,'recordExternalStatus'),'Existing signed delivery webhook remains the canonical provider boundary.');

$readme=$read('app/Domains/Growth/README.md');
foreach(['V0.47 — Email Delivery & Conversation Feedback Parity','reply_received','complained'] as $needle){
    expectGrowthV0470Architecture(str_contains($readme,$needle),'V0.47 documentation missing: '.$needle);
}

echo "Growth V0.47 Email Delivery Parity architecture: OK\n";
