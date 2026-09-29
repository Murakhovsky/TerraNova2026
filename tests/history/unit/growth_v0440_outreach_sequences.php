<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Domain\OutreachSequencePolicy;
use Domains\Growth\Domain\OutreachSequenceStateMachine;

function expectGrowthV0440(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$policy=new OutreachSequencePolicy(true,['email','linkedin'],3,72,10);
$machine=new OutreachSequenceStateMachine();
$now=new DateTimeImmutable('2026-09-26T12:00:00+00:00');

$awaiting=$machine->evaluate($policy,'email',1,false,null,null,$now);
expectGrowthV0440(($awaiting['code']??null)==='awaiting_execution','Sequence must wait for governed execution.');

$delay=$machine->evaluate(
    $policy,'email',1,true,null,new DateTimeImmutable('2026-09-25T12:00:00+00:00'),$now
);
expectGrowthV0440(($delay['code']??null)==='follow_up_delay','Email must respect follow-up delay.');

$advance=$machine->evaluate(
    $policy,'email',1,true,null,new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($advance['action']??null)==='advance','Due email sequence should advance.');

$complete=$machine->evaluate(
    $policy,'email',3,true,null,new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($complete['code']??null)==='max_touches_reached','Touch budget must terminate sequence.');

$linkedinPending=$machine->evaluate(
    $policy,'linkedin',1,true,null,new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($linkedinPending['code']??null)==='delivery_observation_pending','LinkedIn must wait for provider feedback.');

$linkedinFailed=$machine->evaluate(
    $policy,'linkedin',1,true,'failed',new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($linkedinFailed['code']??null)==='delivery_failed','Delivery failure must stop sequence.');

$phonePolicy=new OutreachSequencePolicy(true,['phone'],3,24,10);
$phoneStarted=$machine->evaluate(
    $phonePolicy,'phone',1,true,'started',new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($phoneStarted['code']??null)==='phone_outcome_pending','Started phone call must wait for terminal outcome.');

$phoneCompleted=$machine->evaluate(
    $phonePolicy,'phone',1,true,'completed',new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now
);
expectGrowthV0440(($phoneCompleted['code']??null)==='phone_completed','Completed phone conversation must stop sequence.');

$reply=$machine->evaluate(
    $policy,'email',1,true,null,new DateTimeImmutable('2026-09-20T12:00:00+00:00'),$now,'outcome_reply_received'
);
expectGrowthV0440(($reply['action']??null)==='stop','Authoritative reply outcome must stop sequence.');

echo "Growth V0.44 Outreach Sequence state machine contracts passed.\n";
