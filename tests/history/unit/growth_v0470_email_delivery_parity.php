<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\EngagementDeliveryStatus;
use Domains\Growth\Domain\OutreachSequencePolicy;
use Domains\Growth\Domain\OutreachSequenceStateMachine;

function expectGrowthV0470(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

foreach([
    EngagementDeliveryStatus::Queued,EngagementDeliveryStatus::Accepted,EngagementDeliveryStatus::Sent,
    EngagementDeliveryStatus::Delivered,EngagementDeliveryStatus::Bounced,EngagementDeliveryStatus::Complained,
    EngagementDeliveryStatus::Failed,
] as $status){
    expectGrowthV0470($status->supportsChannel(EngagementChannel::Email),'Email status not supported: '.$status->value);
}
expectGrowthV0470(EngagementDeliveryStatus::Bounced->isTerminal(),'Bounced email must be terminal.');
expectGrowthV0470(EngagementDeliveryStatus::Complained->isTerminal(),'Complaint must be terminal.');
expectGrowthV0470(!EngagementDeliveryStatus::Queued->isTerminal(),'Queued email must not be terminal.');

$machine=new OutreachSequenceStateMachine();
$policy=new OutreachSequencePolicy(true,['email'],3,72,10);
$now=new DateTimeImmutable('2026-09-26T12:00:00+00:00');
$anchor=new DateTimeImmutable('2026-09-26T11:00:00+00:00');

$queued=$machine->evaluate($policy,'email',1,true,'queued',$anchor,$now);
expectGrowthV0470(($queued['code']??null)==='email_delivery_pending','Queued email must wait for provider progress.');

$bounced=$machine->evaluate($policy,'email',1,true,'bounced',$anchor,$now);
expectGrowthV0470(($bounced['action']??null)==='stop'&&($bounced['code']??null)==='delivery_bounced','Bounce must stop sequence.');

$complained=$machine->evaluate($policy,'email',1,true,'complained',$anchor,$now);
expectGrowthV0470(($complained['action']??null)==='stop'&&($complained['code']??null)==='delivery_complained','Complaint must stop sequence.');

echo "Growth V0.47 Email Delivery Parity contracts passed.\n";
