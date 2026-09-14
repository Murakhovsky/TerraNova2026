<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DateTimeImmutable;
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;

final class DiagnosticStateBuilder
{
    /** @param list<Fact> $facts @param list<Evidence> $evidence @param list<Hypothesis> $hypotheses @param list<RootCause> $rootCauses @param list<Recommendation> $recommendations */
    public function build(string $diagnosticId, CompiledDiagnosticPack $pack, array $facts, array $evidence, DiagnosticResult $result, array $hypotheses=[], array $rootCauses=[], array $recommendations=[], int $revision=1, ?DateTimeImmutable $computedAt=null): DiagnosticState
    {
        $known=[]; $contradictions=[];
        foreach ($facts as $fact) {
            if ($fact->status===FactStatus::Known) $known[$fact->key]=$fact;
            if ($fact->status===FactStatus::Contradicted) $contradictions[$fact->key]=$fact;
        }
        $missing=array_values(array_diff(array_keys($pack->factsById),array_keys($known)));
        $blocked=[]; $applicable=[];
        foreach ($result->dependencies as $dependency) {
            if (($dependency->status ?? null)==='BLOCKED') $blocked[]=$dependency->node;
            elseif (($dependency->status ?? null)==='SATISFIED') $applicable[]=$dependency->node;
        }
        return new DiagnosticState($diagnosticId,$pack->pack->id,$pack->pack->version,$revision,$computedAt??new DateTimeImmutable(),$known,$missing,$contradictions,$evidence,$result->assessments,$result->findings,$hypotheses,$rootCauses,$recommendations,[] ,['pack'=>$result->coverage->ratio],$result->confidence,['pack'=>$result->score,'sections'=>$result->sectionScores],array_values(array_unique($blocked)),array_values(array_unique($applicable)),array_values(array_unique([...array_map(fn($f)=>$f->id,$facts),...array_map(fn($e)=>$e->id,$evidence)])));
    }
}
