<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use DomainException;
use Domains\Growth\Domain\GrowthExperiment;
use Domains\Growth\Domain\GrowthExperimentAssignment;
use Domains\Growth\Domain\GrowthExperimentAssignmentSource;
use Domains\Growth\Domain\GrowthExperimentDimension;
use Domains\Growth\Domain\GrowthExperimentStatus;
use Domains\Growth\Domain\GrowthExperimentVariant;
use Domains\Growth\Domain\GrowthOutcomeType;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV0190(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$org=OrganizationId::fromString('org-1');
$a=new GrowthExperimentVariant('a','Variant A',50,['message_angle'=>'problem']);
$b=new GrowthExperimentVariant('b','Variant B',50,['message_angle'=>'outcome']);
$experiment=new GrowthExperiment(
    'experiment-1',$org,'Message test','Outcome framing should improve replies.',
    GrowthExperimentDimension::Message,GrowthOutcomeType::ReplyReceived,[$a,$b],
    new DateTimeImmutable('2026-09-23T08:00:00+00:00'),
);
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Draft,'Growth experiment must start as draft.');

$choice1=$experiment->chooseVariant('candidate-42');
$choice2=$experiment->chooseVariant('candidate-42');
expectGrowthV0190($choice1->key===$choice2->key,'Growth deterministic experiment assignment must be stable.');
expectGrowthV0190(in_array($choice1->key,['a','b'],true),'Growth deterministic experiment assignment chose unknown variant.');

$experiment->start(new DateTimeImmutable('2026-09-23T08:10:00+00:00'));
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Running,'Growth experiment start failed.');
try{
    $experiment->archive();
    throw new RuntimeException('Running Growth experiment must not archive.');
}catch(DomainException){}
$experiment->pause();
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Paused,'Growth experiment pause failed.');
$experiment->resume();
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Running,'Growth experiment resume failed.');
$experiment->complete(new DateTimeImmutable('2026-09-23T10:00:00+00:00'));
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Completed,'Growth experiment completion failed.');
expectGrowthV0190($experiment->endedAt()?->format(DATE_ATOM)==='2026-09-23T10:00:00+00:00','Growth experiment completion time is wrong.');
$experiment->archive();
expectGrowthV0190($experiment->status()===GrowthExperimentStatus::Archived,'Completed Growth experiment archive failed.');

$assignment=new GrowthExperimentAssignment(
    'assignment-1',$org,'experiment-1','candidate-42',$choice1->key,
    GrowthExperimentAssignmentSource::Deterministic,
    ['candidate_id'=>'candidate-42','status'=>'qualified'],
    new DateTimeImmutable('2026-09-23T08:15:00+00:00'),
);
expectGrowthV0190(($assignment->toArray()['variant_key']??null)===$choice1->key,'Growth experiment assignment lost variant.');
expectGrowthV0190(($assignment->toArray()['assignment_source']??null)==='deterministic','Growth experiment assignment lost source.');

try{
    new GrowthExperiment(
        'bad',$org,'Bad','Duplicate keys are invalid.',
        GrowthExperimentDimension::Offer,GrowthOutcomeType::Won,[$a,new GrowthExperimentVariant('a','Duplicate',50,['offer'=>'x'])],
        new DateTimeImmutable(),
    );
    throw new RuntimeException('Duplicate Growth experiment variant keys must fail.');
}catch(InvalidArgumentException){}

echo "Growth V0.19 Experiments & Attribution contracts passed.\n";
