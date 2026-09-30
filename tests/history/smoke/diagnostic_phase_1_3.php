<?php
declare(strict_types=1);
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Input\ObservedValue;
use Domains\Diagnostic\Methodology\PackCompiler;
use Domains\Diagnostic\Model\Assessment;
use Domains\Diagnostic\Model\AssessmentStatus;
use Domains\Diagnostic\Model\Fact;
use Domains\Diagnostic\Model\FactStatus;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Model\HypothesisStatus;
require dirname(__DIR__,2).'/vendor/autoload.php';
function phaseEnsure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$now=new DateTimeImmutable('2026-09-05T10:00:00+03:00');
$fact=new Fact('fact-1','diagnostic-1','crm_enabled',true,'boolean',FactStatus::Known,.9,'crm',['e1'],$now,$now);
$revised=$fact->revise(false,FactStatus::Contradicted,.5,'CRM export conflicts with interview','crm',['e2'],$now->modify('+1 hour'));
phaseEnsure(count($revised->revisions)===1 && $revised->revisions[0]->previousValue===true,'Fact history is not append-only.');
new Assessment('criterion',AssessmentStatus::Unknown,null,.2,[],'Insufficient data');
$hypothesis=new Hypothesis('h1','Routing delays response',HypothesisStatus::Open,.3,[],[],['crm_log']);
phaseEnsure($hypothesis->transition(HypothesisStatus::Supported,.7)->status===HypothesisStatus::Supported,'Hypothesis lifecycle failed.');
$compiled=(new PackCompiler())->compile(dirname(__DIR__,2).'/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json');
phaseEnsure(count($compiled->sectionsById)===12 && count($compiled->criteriaById)===60 && count($compiled->metricsById)===36 && count($compiled->rulesById)===96,'Sales pack size contract failed.');
$signal=new EvidenceSignal('crm','CRM',.95,1,$now);
$base=[];foreach($compiled->metricsById as $id=>$definition)$base[$id]=new ObservedValue($definition->direction==='lower_is_better'?10:90,[$signal]);
$baseFacts=[];foreach($compiled->factsById as $id=>$definition)$baseFacts[$id]=new ObservedValue(true,[$signal]);
$scenarios=[
 'healthy'=>[],
 'slow-response'=>['lead_response_time'=>90,'contact_rate'=>40],
 'weak-qualification'=>['qualification_rate'=>35,'qualified_lead_rate'=>40],
 'broken-pipeline'=>['pipeline_coverage'=>35,'stage_conversion_rate'=>40,'process_compliance'=>30],
 'poor-crm-forecast'=>['crm_completeness'=>30,'duplicate_rate'=>40,'forecast_accuracy'=>35],
];
$engine=new MethodologyEngine();$findingCounts=[];
foreach($scenarios as $name=>$overrides){$values=$base;foreach($overrides as $id=>$value)$values[$id]=new ObservedValue($value,[$signal]);$result=$engine->evaluate(new DiagnosticInput($baseFacts,$values,$now),$compiled->pack);phaseEnsure($result->score!==null && $result->coverage->ratio===1.0,$name.' did not produce a deterministic result.');$findingCounts[$name]=count($result->findings);}
phaseEnsure($findingCounts['healthy']===0 && min(array_slice($findingCounts,1))>0,'Golden scenarios did not distinguish healthy and weak organisations.');
echo "Diagnostic Phase 1-3 contract passed.\n";
