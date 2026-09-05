<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Methodology;
use Domains\Diagnostic\Methodology\Model\MethodologyPack;
final readonly class CompiledDiagnosticPack
{
    public array $sectionsById; public array $criteriaById; public array $factsById; public array $metricsById; public array $rulesById;
    public array $dependenciesUpstream; public array $dependenciesDownstream; public array $criterionToFacts; public array $criterionToMetrics; public array $criterionToRules;
    public function __construct(public MethodologyPack $pack, public string $compilerVersion='1.0.0')
    {
        $index=static fn(array $items):array=>array_column($items,null,'id');
        $this->sectionsById=$index($pack->sections); $this->criteriaById=$index($pack->criteria); $this->factsById=$index($pack->facts); $this->metricsById=$index($pack->metrics); $this->rulesById=$index($pack->rules);
        $up=[]; $down=[]; foreach($pack->dependencies as $d){$up[$d->target][]=$d;$down[$d->source][]=$d;} $this->dependenciesUpstream=$up; $this->dependenciesDownstream=$down;
        $facts=[];$metrics=[];$rules=[]; foreach($pack->criteria as $c){foreach(array_merge($c->required,$c->optional) as $r){if(str_starts_with($r,'fact.'))$facts[$c->id][]=substr($r,5);else $metrics[$c->id][]=str_starts_with($r,'metric.')?substr($r,7):$r;}} foreach($pack->rules as $r)$rules[$r->criterionId][]=$r->id;
        $this->criterionToFacts=$facts;$this->criterionToMetrics=$metrics;$this->criterionToRules=$rules;
    }
}
