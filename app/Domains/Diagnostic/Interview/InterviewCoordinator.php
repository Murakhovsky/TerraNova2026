<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
use DateTimeImmutable;
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\{DiagnosticInput,EvidenceSignal,ObservedValue};
use Domains\Diagnostic\Model\{DiagnosticState,DiagnosticStateBuilder,Evidence,EvidenceType,Fact,FactStatus};

final readonly class InterviewCoordinator
{
    public function __construct(private FactExtractionService $extractor,private ContradictionDetectionService $contradictions=new ContradictionDetectionService(),private NextBestQuestionEngine $questions=new NextBestQuestionEngine(),private MethodologyEngine $methodology=new MethodologyEngine(),private DiagnosticStateBuilder $states=new DiagnosticStateBuilder()){}
    public function processAnswer(string $organizationId,DiagnosticState $current,CompiledDiagnosticPack $pack,NextQuestionDecision $question,string $answer,array $facts,array $evidence,DiagnosticMode $mode,array $askedQuestions,int $remainingQuestions,int $remainingMinutes):InterviewTurnResult
    {
        $now=new DateTimeImmutable();$extracted=$this->extractor->extract($organizationId,$current->diagnosticId,$question->question,$answer,$pack,$current->knownFacts);$newEvidence=[];
        foreach($extracted->candidateEvidence as $i=>$item){if(!is_array($item))continue;$id=(string)($item['id']??substr(hash('sha256',$current->diagnosticId.':'.$question->questionId.':'.$i),0,32));$newEvidence[]=new Evidence($id,EvidenceType::Interview,(string)($item['title']??'Interview answer'),'diagnostic_interview',$now,['question_id'=>$question->questionId],null,'ai_extraction',.6,.7,null,null,$item['value']??$answer);}
        $validEvidenceIds=array_map(fn(Evidence $e)=>$e->id,$newEvidence);$newFacts=[];foreach($extracted->candidateFacts as $candidate){$refs=array_values(array_intersect($candidate->evidenceIds,$validEvidenceIds));if($refs===[]&&$newEvidence!==[])$refs=[$newEvidence[0]->id];$newFacts[]=new Fact(substr(hash('sha256',$current->diagnosticId.':'.$candidate->key),0,32),$current->diagnosticId,$candidate->key,$candidate->value,$candidate->valueType,FactStatus::Known,$candidate->confidence,$candidate->provenance,$refs,$now,$now);}
        $detected=$this->contradictions->detect($facts,$newFacts===[]?[]:$extracted->candidateFacts);$mergedFacts=$this->mergeFacts($facts,$newFacts,$detected,$now);$mergedEvidence=[...$evidence,...$newEvidence];$inputFacts=[];foreach($mergedFacts as $fact)if($fact->status===FactStatus::Known){$signals=[];foreach($fact->evidenceIds as $id)$signals[]=new EvidenceSignal($id,$fact->source,.6,1,$now,$fact->value);$inputFacts[$fact->key]=new ObservedValue($fact->value,$signals);}$metrics=[];foreach($extracted->metricInputs as $metric){$id=(string)($metric['key']??'');if(isset($pack->metricsById[$id])&&isset($metric['value'])&&is_numeric($metric['value']))$metrics[$id]=new ObservedValue((float)$metric['value']);}$result=$this->methodology->evaluate(new DiagnosticInput($inputFacts,$metrics,$now),$pack->pack);$state=$this->states->build($current->diagnosticId,$pack,$mergedFacts,$mergedEvidence,$result,$current->hypotheses,$current->rootCauses,$current->recommendations,$current->revision+1,$now);$next=$this->questions->decide($state,$pack,[...$askedQuestions,$question->questionId],$mode,$remainingQuestions-1,$remainingMinutes);return new InterviewTurnResult($mergedFacts,$mergedEvidence,$detected,$state,$next);
    }
    private function mergeFacts(array $old,array $new,array $contradictions,DateTimeImmutable $now):array{$byKey=[];foreach($old as $fact)$byKey[$fact->key]=$fact;$conflicted=[];foreach($contradictions as $c)foreach($new as $f)if(str_starts_with($c->statementA,$f->key.' ='))$conflicted[$f->key]=true;foreach($new as $fact){if(isset($byKey[$fact->key]))$byKey[$fact->key]=$byKey[$fact->key]->revise($fact->value,isset($conflicted[$fact->key])?FactStatus::Contradicted:FactStatus::Known,$fact->confidence,'Interview answer',$fact->source,$fact->evidenceIds,$now);else $byKey[$fact->key]=$fact;}return array_values($byKey);}
}
