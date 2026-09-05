<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Model\DiagnosticState;

final class NextBestQuestionEngine
{
    public function decide(DiagnosticState $state,CompiledDiagnosticPack $pack,array $askedQuestions,DiagnosticMode $mode,int $remainingQuestionBudget,int $remainingTimeMinutes):?NextQuestionDecision
    {
        if($remainingQuestionBudget<=0||$remainingTimeMinutes<=0)return null; $candidates=[];
        foreach($state->missingFacts as $factId){$fact=$pack->factsById[$factId]??null;if($fact===null)continue;$criteria=[];$importance=1.0;$unlock=1.0;
            foreach($pack->criterionToFacts as $criterionId=>$facts)if(in_array($factId,$facts,true)){$criteria[]=$criterionId;$importance+=($pack->criteriaById[$criterionId]->weight??1.0);$unlock+=count($pack->dependenciesDownstream[$criterionId]??[]);}
            $questionId='fact:'.$factId;if(in_array($questionId,$askedQuestions,true))continue;$fatigue=1+count(array_filter($askedQuestions,fn($id)=>str_starts_with((string)$id,'fact:')))*0.05;$cost=$mode===DiagnosticMode::QuickScan?1.25:1.0;$gain=min(5.0,1+count($criteria));$score=($importance*1.0*1.0*$gain*$unlock)/($cost*$fatigue);
            $candidates[]=new NextQuestionDecision($questionId,'Розкажіть, будь ласка: '.$fact->name.'?',round($score,4),'Закриває відсутній методологічний факт.',$criteria,[$factId],$gain,$unlock);
        }
        usort($candidates,fn($a,$b)=>$b->priorityScore<=>$a->priorityScore?:strcmp($a->questionId,$b->questionId));return $candidates[0]??null;
    }
}
