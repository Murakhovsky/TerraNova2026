<?php
declare(strict_types=1);

use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Input\ObservedValue;
use Domains\Diagnostic\Methodology\PackCompiler;
use Domains\Diagnostic\Model\DiagnosticStateBuilder;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use Domains\Diagnostic\Model\Fact;
use Domains\Diagnostic\Model\FactStatus;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Model\HypothesisStatus;
use Domains\Diagnostic\Interview\RootCauseAnalysisService;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition) throw new RuntimeException($message);
};

$root=dirname(__DIR__,2);
$compiled=(new PackCompiler())->compile($root.'/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json');
$assert($compiled->contentHash!=='' && $compiled->compilerVersion===PackCompiler::COMPILER_VERSION,'Compiled pack metadata is incomplete.');

$at=new DateTimeImmutable('2026-09-28T08:00:00+00:00');
$evidence=new Evidence('ev-crm',EvidenceType::SystemData,'CRM export','crm://v1',$at,['confidence'=>.95,'quality'=>1.0]);
$signal=new EvidenceSignal($evidence->id,$evidence->type->value,.95,1.0,$at);

$facts=[];$factInput=[];
foreach($compiled->factsById as $id=>$definition){
    $value=true;
    $facts[]=new Fact('fact:'.$id,'diagnostic-v1',$id,$value,$definition->type,FactStatus::Known,.95,'CRM',[$evidence->id],$at,$at);
    $factInput[$id]=new ObservedValue($value,[$signal]);
}
$metrics=[];
foreach($compiled->metricsById as $id=>$definition){
    $metrics[$id]=new ObservedValue($definition->direction==='lower_is_better'?10:90,[$signal]);
}

$engine=new MethodologyEngine();
$result=$engine->evaluate(new DiagnosticInput($factInput,$metrics,$at),$compiled->pack);
$assert($result->coverage->ratio===1.0,'Canonical V1 input did not reach full deterministic coverage.');

$builder=new DiagnosticStateBuilder();
$stateA=$builder->build('diagnostic-v1',$compiled,$facts,[$evidence],$result,[],[],[],1,$at);
$stateB=$builder->build('diagnostic-v1',$compiled,$facts,[$evidence],$result,[],[],[],1,$at);
$normalize=static fn($state):string=>json_encode([
    'pack'=>$state->packId,
    'pack_version'=>$state->packVersion,
    'revision'=>$state->revision,
    'known'=>array_keys($state->knownFacts),
    'missing'=>$state->missingFacts,
    'gaps'=>$state->evidenceGaps,
    'coverage'=>$state->coverage,
    'confidence'=>$state->confidence,
    'scores'=>$state->scores,
    'inputs'=>$state->inputIds,
],JSON_THROW_ON_ERROR);
$assert($normalize($stateA)===$normalize($stateB),'DiagnosticState rebuild is not deterministic.');

$hypothesis=(new Hypothesis('hyp:v1','Routing ownership causes delay',HypothesisStatus::Unverified,.9,['ev-crm','ev-crm-2']))
    ->transition(HypothesisStatus::Supported,.9);
$confirmed=(new RootCauseAnalysisService())->confirm([$hypothesis],1.0,.75,2,.75,.25);
$assert(count($confirmed)===1 && $confirmed[0]->status===HypothesisStatus::ConfirmedRootCause,'Deterministic root-cause confirmation failed.');

echo "Diagnostic V1 vertical slice passed: compile -> deterministic evaluate -> rebuild -> root-cause confirmation.\n";
