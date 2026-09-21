<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Bootstrap\GrowthDomainModule;
use Domains\Growth\Domain\GrowthMode;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\OpportunityCandidateStatus;
use Domains\Growth\Domain\OpportunityRationale;
use Domains\Growth\Domain\OpportunityScore;
use Domains\Growth\Domain\OpportunityType;
use Domains\Growth\Domain\ScoreDimension;
use Kernel\Shared\Domain\OrganizationId;

function expectGrowthV020(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$rationale=new OpportunityRationale(
    'Operational change matters','Scaling may expose process gaps','Leadership changed this week',
    ['signal-1'],['counter-1'],['budget exists'],['decision maker'],0.77,
);
$rationaleRoundTrip=OpportunityRationale::fromArray($rationale->toArray());
expectGrowthV020($rationaleRoundTrip->whyNow===$rationale->whyNow,'Growth rationale persistence round-trip failed.');

$dimension=new ScoreDimension(83,'Evidence-backed dimension',['signal-1'],'growth-score-v1');
$score=new OpportunityScore($dimension,$dimension,$dimension,$dimension,$dimension,0.81);
$scoreRoundTrip=OpportunityScore::fromArray($score->toArray());
expectGrowthV020($scoreRoundTrip->timing->score===83,'Growth score persistence round-trip failed.');

$candidate=OpportunityCandidate::restore(
    'candidate-1',OrganizationId::fromString('org-1'),OpportunityType::CustomerAcquisition,GrowthMode::Acquire,
    'company','acme','sales',['signal-1'],OpportunityCandidateStatus::Qualified,$rationale,$score,'Qualified by evidence',
);
$candidate->prepareHandoff('Potential implementation','sales_diagnostic','research buying committee');
expectGrowthV020($candidate->status()===OpportunityCandidateStatus::ReadyForHandoff,'Restored Growth candidate could not continue lifecycle.');

expectGrowthV020(count(GrowthEventType::values())===8,'Growth V0.2 must expose eight canonical events.');
expectGrowthV020(count(array_unique(GrowthEventType::values()))===8,'Growth event types must be unique.');
$module=new GrowthDomainModule();
expectGrowthV020($module->name()==='growth','Growth runtime module name mismatch.');
foreach(GrowthEventType::values() as $type){
    expectGrowthV020(in_array($type,$module->eventTypes(),true),'Growth module must own event '.$type);
}

$boundary=new ReflectionClass(GrowthApplicationBoundary::class);
foreach([
    'detectSignal','detectCandidate','researchCandidate','scoreCandidate','qualifyCandidate',
    'monitorCandidate','disqualifyCandidate','prepareHandoff','viewSignal','viewCandidate',
] as $method){
    expectGrowthV020($boundary->hasMethod($method),'Growth application boundary missing '.$method.'.');
}

echo "Growth V0.2 runtime contracts passed.\n";
