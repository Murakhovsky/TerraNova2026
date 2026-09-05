<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Report;
use Domains\Diagnostic\Model\DiagnosticState;
final class DiagnosticReportBuilder
{
    public function build(DiagnosticState $state,array $metrics=[],string $summary=''):DiagnosticReport{$critical=array_values(array_filter($state->findings,fn($f)=>in_array($f->severity??'', ['high','critical'],true)));$quick=array_values(array_filter($state->recommendations,fn($r)=>($r->details['quick_win']??false)===true));return new DiagnosticReport($state->diagnosticId,$summary,$state->scores['pack']??null,(float)($state->coverage['pack']??0),$state->confidence,$state->findings,[],array_slice($critical,0,5),[],array_slice($quick,0,3),$state->scores['sections']??[],$metrics,$state->findings,$state->rootCauses,$state->recommendations,(new RoadmapBuilder())->build($state->recommendations),$state->evidence,$state->missingFacts,$state->contradictions);}
}
