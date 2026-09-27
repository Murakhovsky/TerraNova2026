<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\QualificationOutcome;
use Domains\Growth\Domain\QualificationPolicy;
use Domains\Growth\Domain\QualificationPolicyCriteria;
use Domains\Growth\Domain\QualificationPolicyEvaluator;
use Domains\Growth\Domain\ScoreDimension;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV060(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

function growthScore(int $fit,int $need,int $timing,int $access,int $value,float $confidence): OpportunityScore
{
    $d=static fn(int $score,string $name):ScoreDimension=>new ScoreDimension($score,$name.' evidence',['signal-1'],'model-v1');
    return new OpportunityScore($d($fit,'fit'),$d($need,'need'),$d($timing,'timing'),$d($access,'access'),$d($value,'value'),$confidence);
}

$org=OrganizationId::fromString('org-1');
$criteria=new QualificationPolicyCriteria(
    ['fit'=>70,'need'=>70,'timing'=>60,'access'=>50,'value'=>60],
    ['fit'=>30,'need'=>30],
    0.7,
);
$policy=QualificationPolicy::draft('policy-1',$org,'Default Growth Policy',$criteria);
$policy->activate();
$rationale=new OpportunityRationale(
    'Material operational change','Scaling creates process risk','Leadership change makes timing relevant',
    ['signal-1'],[],[],['budget owner'],0.82,
);
$at=new DateTimeImmutable('2026-09-22T09:00:00+00:00');
$evaluator=new QualificationPolicyEvaluator();

$qualified=$evaluator->evaluate('candidate-1',$policy,$rationale,growthScore(85,90,75,65,80,0.9),$at);
expectGrowthV060($qualified->outcome===QualificationOutcome::Qualified,'Qualification policy should qualify strong candidate.');
expectGrowthV060($qualified->failedCriteria===[],'Qualified candidate must not expose failed criteria.');

$monitor=$evaluator->evaluate('candidate-2',$policy,$rationale,growthScore(65,80,70,55,75,0.85),$at);
expectGrowthV060($monitor->outcome===QualificationOutcome::Monitor,'Candidate between hard reject and qualification threshold should be monitored.');
expectGrowthV060(in_array('fit.minimum',$monitor->failedCriteria,true),'Monitor decision must expose failed dimension.');

$lowResearch=new OpportunityRationale(
    'Possible change','Hypothesis remains weak','Timing is not well verified',
    ['signal-1'],[],[],['decision process'],0.4,
);
$uncertain=$evaluator->evaluate('candidate-uncertain',$policy,$lowResearch,growthScore(85,90,75,65,80,0.9),$at);
expectGrowthV060($uncertain->outcome===QualificationOutcome::Monitor,'Low research confidence must prevent qualification.');
expectGrowthV060(in_array('rationale_confidence.minimum',$uncertain->failedCriteria,true),'Research confidence gap must be explicit.');

$rejected=$evaluator->evaluate('candidate-3',$policy,$rationale,growthScore(20,90,80,70,80,0.9),$at);
expectGrowthV060($rejected->outcome===QualificationOutcome::Disqualified,'Hard reject threshold must disqualify candidate.');
expectGrowthV060(in_array('fit.hard_reject',$rejected->failedCriteria,true),'Hard reject decision must expose failed criterion.');
expectGrowthV060(($qualified->toArray()['model_version']??null)==='growth-qualification-v1','Qualification decision model version must be explicit.');

echo "Growth V0.6 Decision Intelligence contracts passed.\n";
