<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Interview;

use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Methodology\Model\QuestionDefinition;
use Domains\Diagnostic\Model\DiagnosticState;

final class NextBestQuestionEngine
{
    public function decide(DiagnosticState $state, CompiledDiagnosticPack $pack, array $questionHistory, DiagnosticMode $mode, int $remainingQuestionBudget, int $remainingTimeMinutes): ?NextQuestionDecision
    {
        if ($remainingQuestionBudget <= 0 || $remainingTimeMinutes <= 0) return null;
        $history = $this->history($questionHistory); $candidates = [];
        foreach ($pack->questionsById as $question) {
            if (!$question->enabled || isset($history['asked'][$question->id])) continue;
            if ($question->allowedDiagnosticModes !== [] && !in_array($mode->value, $question->allowedDiagnosticModes, true)) continue;
            $missing = count(array_intersect($question->targetFacts, $state->missingFacts));
            $conflicts = $this->contradictions($question, $state); $hypotheses = $this->hypotheses($question, $state); $evidence = $this->evidenceGap($question, $pack, $state);
            if (!$missing && !$conflicts && !$hypotheses && !$evidence) continue;
            $criteria = array_values(array_filter($question->targetCriteria, fn ($id) => isset($pack->criteriaById[$id])));
            $importance = $question->priority + array_sum(array_map(fn ($id) => $this->importance($pack->criteriaById[$id]->importance) * $pack->criteriaById[$id]->weight, $criteria));
            $unlock = 1.0; foreach ($criteria as $id) $unlock += count($pack->dependenciesDownstream[$id] ?? []) + count($pack->criterionToRules[$id] ?? []);
            $gain = 1 + $missing + 1.5 * $evidence + 2 * $conflicts + $hypotheses + min(3, count($criteria));
            $uncertainty = min(1, .25 + .3 * $missing + .2 * $evidence + .25 * $conflicts);
            $fatigue = 1 + $history['count'] * .04 + $history['skipped'] * .2 + ($history['areas'][$question->areaId] ?? 0) * .12;
            $modeCost = $mode === DiagnosticMode::QuickScan ? 1.3 : ($mode === DiagnosticMode::EvidenceValidation ? .85 : 1);
            $score = ($importance * $uncertainty * $gain * $unlock) / max(.1, $question->cost * $modeCost * $fatigue);
            $reason = array_filter([$missing?'fills missing facts':'',$evidence?'closes an evidence gap':'',$conflicts?'resolves contradictions':'',$hypotheses?'tests hypotheses':'']);
            $candidates[] = new NextQuestionDecision($question->id, $question->text, round($score, 4), ucfirst(implode(', ', $reason)).'.', $criteria, $question->targetFacts, round($gain, 4), round($unlock, 4));
        }
        usort($candidates, fn ($a, $b) => $b->priorityScore <=> $a->priorityScore ?: strcmp($a->questionId, $b->questionId));
        return $candidates[0] ?? null;
    }

    private function importance(string|float $value): float {return is_float($value) ? $value : match (strtolower($value)) {'low'=>.5,'medium'=>1.0,'high'=>1.5,'critical'=>2.0,default=>1.0};}
    private function contradictions(QuestionDefinition $q, DiagnosticState $s): int {$n=0;foreach($s->contradictions as $key=>$c){$text=(is_string($key)?$key:'').' '.($c->statementA??'').' '.($c->statementB??'');foreach($q->targetFacts as $f)if(str_contains($text,$f)){$n++;break;}}return $n;}
    private function hypotheses(QuestionDefinition $q, DiagnosticState $s): int {$n=0;foreach($s->hypotheses as $h)foreach($h->requiredEvidence??[] as $r)if(in_array($r,$q->targetFacts,true)||$r===$q->evidenceRequirementId)$n++;return $n;}
    private function evidenceGap(QuestionDefinition $q, CompiledDiagnosticPack $p, DiagnosticState $s): int {if($q->evidenceRequirementId===null)return 0;$r=$p->evidenceRequirementsById[$q->evidenceRequirementId]??null;if($r===null)return 0;$sources=[];foreach($s->evidence as $e){$source=strtoupper((string)($e->type->value??$e->sourceType??''));if(in_array($source,$r->acceptedSourceTypes,true)&&($e->reliability??0)>=$r->minimumReliability&&($e->directness??0)>=$r->minimumDirectness)$sources[$source]=true;}return count($sources)<$r->minimumSources?1:0;}
    private function history(array $entries): array {$result=['asked'=>[],'count'=>count($entries),'skipped'=>0,'areas'=>[]];foreach($entries as $entry){if(is_string($entry)){$result['asked'][$entry]=true;continue;}$id=(string)($entry['question_id']??'');if($id!=='')$result['asked'][$id]=true;if(in_array($entry['status']??'',['SKIPPED','REFUSED'],true))$result['skipped']++;$area=(string)($entry['area_id']??'');if($area!=='')$result['areas'][$area]=($result['areas'][$area]??0)+1;}return $result;}
}
