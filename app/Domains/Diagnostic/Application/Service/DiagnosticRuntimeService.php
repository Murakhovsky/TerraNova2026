<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Domains\Diagnostic\Application\Contract\DiagnosticSessionRepositoryInterface;
use Domains\Diagnostic\Application\DTO\StartDiagnosticSessionCommand;
use Domains\Diagnostic\Application\UseCase\AcceptDiagnosticRecommendation;
use Domains\Diagnostic\Application\UseCase\CaptureDiagnosticEvidence;
use Domains\Diagnostic\Application\UseCase\CompleteDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\EvaluateDiagnosticSession;
use Domains\Diagnostic\Application\UseCase\RecordDiagnosticResult;
use Domains\Diagnostic\Application\UseCase\StartDiagnosticSession;
use Domains\Diagnostic\Evaluation\ReDiagnosticSchedule;
use Domains\Diagnostic\Interview\DiagnosticMode;
use Domains\Diagnostic\Interview\FactExtractionService;
use Domains\Diagnostic\Interview\HypothesisGenerationService;
use Domains\Diagnostic\Interview\NextBestQuestionEngine;
use Domains\Diagnostic\Interview\RootCauseAnalysisService;
use Domains\Diagnostic\Methodology\CompiledDiagnosticPack;
use Domains\Diagnostic\Methodology\Engine\MethodologyEngine;
use Domains\Diagnostic\Methodology\Input\DiagnosticInput;
use Domains\Diagnostic\Methodology\Input\EvidenceSignal;
use Domains\Diagnostic\Methodology\Input\ObservedValue;
use Domains\Diagnostic\Model\DiagnosticRecord;
use Domains\Diagnostic\Model\DiagnosticRecordType;
use Domains\Diagnostic\Model\DiagnosticState;
use Domains\Diagnostic\Model\DiagnosticStateBuilder;
use Domains\Diagnostic\Model\DiagnosticTarget;
use Domains\Diagnostic\Model\Evidence;
use Domains\Diagnostic\Model\EvidenceType;
use Domains\Diagnostic\Model\Fact;
use Domains\Diagnostic\Model\FactStatus;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Model\HypothesisStatus;
use Domains\Diagnostic\Model\Recommendation;
use Domains\Diagnostic\Report\DiagnosticReportBuilder;
use Domains\Diagnostic\Report\RecommendationGenerationService;
use Domains\Diagnostic\Report\RecommendationStatus;

final readonly class DiagnosticRuntimeService
{
    public function __construct(
        private DiagnosticRuntimeRepositoryInterface $runtime,
        private DiagnosticSessionRepositoryInterface $sessions,
        private MethodologyStudioService $methodology,
        private StartDiagnosticSession $startSession,
        private CaptureDiagnosticEvidence $captureEvidence,
        private RecordDiagnosticResult $recordResult,
        private EvaluateDiagnosticSession $evaluateSession,
        private CompleteDiagnosticSession $completeSession,
        private FactExtractionService $extractor,
        private HypothesisGenerationService $hypotheses,
        private AcceptDiagnosticRecommendation $acceptRecommendation,
        private NextBestQuestionEngine $questions = new NextBestQuestionEngine(),
        private RootCauseAnalysisService $rootCauses = new RootCauseAnalysisService(),
        private RecommendationGenerationService $recommendations = new RecommendationGenerationService(),
        private DiagnosticReportBuilder $reports = new DiagnosticReportBuilder(),
        private MethodologyEngine $engine = new MethodologyEngine(),
        private DiagnosticStateBuilder $states = new DiagnosticStateBuilder(),
    ) {}

    public function start(string $organizationId, array $input, string $actorId): array
    {
        $now=new DateTimeImmutable();
        $sessionId=trim((string)($input['session_id']??'')) ?: bin2hex(random_bytes(16));
        $packId=trim((string)($input['pack_id']??''));
        if($packId==='') throw new DomainException('pack_id is required.');
        $packMeta=$this->methodology->pack($organizationId,$packId);
        $methodologyVersion=trim((string)($input['methodology_version']??($packMeta['active_methodology_version']??'')));
        $packVersion=(int)($input['pack_version']??($packMeta['active_version']??0));
        $domain=trim((string)($input['domain']??($packMeta['domain']??'')));
        $subjectType=trim((string)($input['subject_type']??'company'));
        $subjectId=trim((string)($input['subject_id']??''));
        $mode=DiagnosticMode::tryFrom((string)($input['mode']??DiagnosticMode::DeepDiagnostic->value)) ?? DiagnosticMode::DeepDiagnostic;
        if($methodologyVersion===''||$packVersion<1||$domain===''||$subjectId==='') throw new DomainException('The pack must have an active published version and subject_id is required.');

        $this->startSession->execute(
            new StartDiagnosticSessionCommand($organizationId,$sessionId,$packId,$packVersion,new DiagnosticTarget($domain,$subjectType,$subjectId)),
            $now,'USER',$actorId
        );
        $questionBudget=max(1,(int)($input['question_budget']??40)); $timeBudget=max(1,(int)($input['time_budget_minutes']??90));
        $state=['pack_id'=>$packId,'methodology_version'=>$methodologyVersion,'idempotency_request_hash'=>(string)($input['idempotency_request_hash']??''),'facts'=>[],'metrics'=>[],'history'=>[],'contradictions'=>[],'revision'=>0,'question_budget'=>$questionBudget,'time_budget_minutes'=>$timeBudget,'remaining_questions'=>$questionBudget,'remaining_minutes'=>$timeBudget];
        $this->runtime->create($organizationId,$sessionId,$mode->value,$state,isset($input['parent_session_id'])?(string)$input['parent_session_id']:null,$now);
        return $this->resume($organizationId,$sessionId);
    }

    public function resume(string $organizationId,string $sessionId):array
    {
        $row=$this->runtime->get($organizationId,$sessionId)??throw new DomainException('Diagnostic runtime was not found.');
        $session=$this->sessions->get($organizationId,$sessionId)??throw new DomainException('Diagnostic session was not found.');
        $state=$this->state($organizationId,$sessionId,$row);
        $next=$row['status']==='active'?$this->nextDecision($row,$state):null;
        return $this->snapshot($row,$session,$state,$next);
    }

    public function nextQuestion(string $organizationId,string $sessionId):?array
    {
        $row=$this->runtime->get($organizationId,$sessionId)??throw new DomainException('Diagnostic runtime was not found.');
        if($row['status']!=='active') return null;
        $state=$this->state($organizationId,$sessionId,$row);
        $next=$this->nextDecision($row,$state);
        return $next===null?null:$this->questionArray($next);
    }

    public function answer(string $organizationId,string $sessionId,string $answer,string $actorId,?string $idempotencyKey=null):array
    {
        if(trim($answer)==='') throw new DomainException('Answer must not be empty.');
        $row=$this->runtime->get($organizationId,$sessionId)??throw new DomainException('Diagnostic runtime was not found.');
        if($row['status']!=='active') throw new DomainException('Diagnostic runtime is not active.');
        $session=$this->sessions->get($organizationId,$sessionId)??throw new DomainException('Diagnostic session was not found.');
        $idempotencyKey=trim((string)$idempotencyKey);
        if($idempotencyKey!==''){
            foreach($row['state']['history']??[] as $turn){
                if(is_array($turn) && ($turn['idempotency_key']??null)===$idempotencyKey){
                    if((string)($turn['answer']??'')!==$answer){
                        throw new DomainException('Diagnostic idempotency key was reused with a different interview answer.');
                    }
                    $state=$this->state($organizationId,$sessionId,$row);
                    $next=$this->nextDecision($row,$state);
                    return $this->snapshot($row,$session,$state,$next)+['replayed'=>true];
                }
            }
        }
        $pack=$this->compiled($organizationId,$row);
        $state=$this->state($organizationId,$sessionId,$row,$pack);
        $questionId=(string)($row['current_question_id']??'');
        $decision=$questionId!==''?$this->decisionFor($questionId,$pack,$state,$row):$this->nextDecision($row,$state,$pack);
        if($decision===null) throw new DomainException('No diagnostic question is currently available.');

        $extracted=$this->extractor->extract($organizationId,$sessionId,$decision->question,$answer,$pack,$state->knownFacts);
        $now=new DateTimeImmutable();
        $newEvidenceIds=[];
        $candidateEvidence=$extracted->candidateEvidence;
        if($candidateEvidence===[]) $candidateEvidence=[['title'=>'Interview answer','value'=>$answer]];
        foreach($candidateEvidence as $i=>$item){
            if(!is_array($item)) continue;
            $seed=$idempotencyKey!==''?$idempotencyKey:(string)count($row['state']['history']);
            $id=substr(hash('sha256',$sessionId.':'.$decision->questionId.':'.$seed.':'.$i),0,64);
            $evidence=new Evidence($id,EvidenceType::Interview,(string)($item['title']??'Interview answer'),'diagnostic_interview',$now,['question_id'=>$decision->questionId,'confidence'=>(float)($item['confidence']??.65),'quality'=>(float)($item['quality']??.8),'claim'=>$item['value']??$answer],null,'ai_extraction',.65,.8,null,null,$item['value']??$answer);
            $this->captureEvidence->execute($organizationId,$sessionId,$evidence,'USER',$actorId);
            $newEvidenceIds[]=$id;
        }

        $runtimeState=$row['state'];
        foreach($extracted->candidateFacts as $candidate){
            $refs=array_values(array_intersect($candidate->evidenceIds,$newEvidenceIds));
            if($refs===[]) $refs=$newEvidenceIds;
            $old=$runtimeState['facts'][$candidate->key]??null;
            if($old!==null && ($old['value']??null)!==$candidate->value){
                $runtimeState['contradictions'][]=['fact'=>$candidate->key,'before'=>$old['value']??null,'after'=>$candidate->value,'question_id'=>$decision->questionId];
            }
            $runtimeState['facts'][$candidate->key]=['value'=>$candidate->value,'value_type'=>$candidate->valueType,'confidence'=>(float)$candidate->confidence,'source'=>$candidate->provenance,'evidence_ids'=>$refs,'updated_at'=>$now->format(DATE_ATOM)];
        }
        foreach($extracted->metricInputs as $metric){
            $key=(string)($metric['key']??'');
            if($key!==''&&isset($pack->metricsById[$key])&&isset($metric['value'])&&is_numeric($metric['value'])){
                $runtimeState['metrics'][$key]=['value'=>(float)$metric['value'],'evidence_ids'=>$newEvidenceIds,'updated_at'=>$now->format(DATE_ATOM)];
            }
        }
        $runtimeState['history'][]=['question_id'=>$decision->questionId,'area_id'=>$pack->questionsById[$decision->questionId]->areaId??'','answer'=>$answer,'status'=>'ANSWERED','at'=>$now->format(DATE_ATOM),'idempotency_key'=>$idempotencyKey!==''?$idempotencyKey:null,'uncertainties'=>$extracted->uncertainties,'missing_information'=>$extracted->missingInformation];
        $runtimeState['remaining_questions']=max(0,(int)$runtimeState['remaining_questions']-1);
        $started=new DateTimeImmutable((string)$row['started_at']);
        $elapsedMinutes=max(0,(int)floor(($now->getTimestamp()-$started->getTimestamp())/60));
        $runtimeState['remaining_minutes']=max(0,(int)($runtimeState['time_budget_minutes']??90)-$elapsedMinutes);
        $runtimeState['revision']=(int)($runtimeState['revision']??0)+1;

        $nextState=$this->stateFromRuntime($sessionId,$pack,$session->evidence(),$runtimeState);
        $next=$this->questions->decide($nextState,$pack,$runtimeState['history'],DiagnosticMode::from((string)$row['mode']),(int)$runtimeState['remaining_questions'],(int)$runtimeState['remaining_minutes']);
        $this->runtime->saveState($organizationId,$sessionId,$runtimeState,$next?->questionId,(int)$runtimeState['revision']);
        $fresh=$this->runtime->get($organizationId,$sessionId)??$row;
        return $this->snapshot($fresh,$this->sessions->get($organizationId,$sessionId)??$session,$nextState,$next)+['replayed'=>false];
    }

    public function complete(string $organizationId,string $sessionId,string $actorId):array
    {
        $row=$this->runtime->get($organizationId,$sessionId)??throw new DomainException('Diagnostic runtime was not found.');
        if($row['status']==='completed') return $this->report($organizationId,$sessionId)+['replayed'=>true];
        $session=$this->sessions->get($organizationId,$sessionId)??throw new DomainException('Diagnostic session was not found.');
        $now=new DateTimeImmutable();
        $this->materializeInputs($organizationId,$sessionId,$row['state'],$session->records(),$now,$actorId);
        $result=$this->evaluateSession->execute($organizationId,$sessionId,$now,'USER',$actorId);
        $session=$this->sessions->get($organizationId,$sessionId)??throw new DomainException('Diagnostic session disappeared after evaluation.');
        $pack=$this->compiled($organizationId,$row);
        $facts=$this->facts($sessionId,$row['state']);
        $base=$this->states->build($sessionId,$pack,$facts,$session->evidence(),$result,[],[],[],(int)$row['state_revision']+1,$now);

        $findingPayload=array_map(fn($f)=>['rule_id'=>$f->ruleId,'criterion_id'=>$f->criterionId,'severity'=>$f->severity,'statement'=>$f->statement,'evidence_ids'=>$f->evidenceIds],$result->findings);
        $evidenceIds=array_map(fn(Evidence $e)=>$e->id,$session->evidence());
        $generated=$findingPayload===[]?[]:$this->hypotheses->generate($organizationId,$sessionId,$findingPayload,$evidenceIds);
        $supported=[];
        foreach($generated as $h){
            if(!$h instanceof Hypothesis) continue;
            $support=count(array_unique($h->supportingEvidence));
            $supported[]=$support>=2?$h->transition(HypothesisStatus::Supported,min(.95,max(.75,.72+.05*$support))):$h;
        }
        $findingIds=array_map(fn($f)=>$f->ruleId,$result->findings);
        $rootCauses=$this->rootCauses->analyze($supported,$findingIds,$result->coverage->ratio);
        $templates=$this->recommendationTemplates($pack);
        $recommendations=$this->recommendations->generate($templates,$findingIds,array_map(fn($r)=>$r->id,$rootCauses));
        $final=$this->states->build($sessionId,$pack,$facts,$session->evidence(),$result,$supported,$rootCauses,$recommendations,(int)$row['state_revision']+1,$now);
        $report=$this->reports->build($final,$row['state']['metrics']??[],sprintf('Diagnostic %s completed with %.1f%% coverage and %.1f/100 health score.',$sessionId,$result->coverage->ratio*100,$result->score));
        $reportArray=json_decode(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true,512,JSON_THROW_ON_ERROR);
        $version=$this->runtime->saveReport($organizationId,$sessionId,$reportArray,(int)$row['state_revision']+1,$now);
        foreach($recommendations as $recommendation){
            $payload=$this->recommendationArray($recommendation);
            $this->runtime->saveRecommendation($organizationId,$sessionId,$recommendation->id,$payload,$recommendation->status->value,$now);
        }
        $this->completeSession->execute($organizationId,$sessionId,$now,'USER',$actorId);
        $this->runtime->complete($organizationId,$sessionId,$now);
        $schedule=new ReDiagnosticSchedule($sessionId,$now,30);
        $scheduled=$this->runtime->schedule($organizationId,$sessionId,30,$schedule->dueAt,$now);
        return ['report_version'=>$version,'report'=>$reportArray,'recommendations'=>array_map(fn($r)=>$this->recommendationArray($r),$recommendations),'re_diagnostic'=>$scheduled,'replayed'=>false];
    }

    public function report(string $organizationId,string $sessionId):array
    {
        $report=$this->runtime->report($organizationId,$sessionId)??throw new DomainException('Diagnostic report was not found.');
        $report['measurements']=$this->runtime->measurements($organizationId,$sessionId);
        $report['recommendations']=$this->runtime->recommendations($organizationId,$sessionId);
        return $report;
    }

    public function accept(
        string $organizationId,
        string $sessionId,
        string $recommendationId,
        ?int $ownerId=null,
        ?DateTimeImmutable $dueAt=null,
        string $workflowCode=AcceptDiagnosticRecommendation::WORKFLOW,
    ):array
    {
        $row=$this->runtime->recommendation($organizationId,$sessionId,$recommendationId)??throw new DomainException('Recommendation was not found.');
        $expectedAssignment=$ownerId!==null&&$dueAt!==null?[
            'owner_id'=>$ownerId,
            'due_at'=>$dueAt->format(DATE_ATOM),
            'workflow_code'=>$workflowCode,
        ]:null;

        if(trim((string)($row['action_id']??''))!==''){
            $stored=is_array($row['payload']['action_assignment']??null)?$row['payload']['action_assignment']:null;
            if($expectedAssignment!==null && $stored!==$expectedAssignment){
                throw new DomainException('Recommendation already has an action with a different operational assignment.');
            }
            return ['recommendation'=>$row['payload'],'action_id'=>(string)$row['action_id'],'action_status'=>'EXISTING','replayed'=>true];
        }

        $r=$this->recommendationFromArray($row['payload']);
        $action=$this->acceptRecommendation->execute($organizationId,$sessionId,$r,$ownerId,$dueAt,$workflowCode);
        $payload=$this->recommendationArray($r);
        $payload['action_assignment']=[
            'owner_id'=>$action->parameters['owner_id']??null,
            'owner_role'=>$action->parameters['owner_role']??null,
            'due_at'=>$action->parameters['due_at']??null,
            'workflow_code'=>$action->parameters['workflow_code']??null,
        ];
        $this->runtime->saveRecommendation($organizationId,$sessionId,$recommendationId,$payload,$r->status->value,new DateTimeImmutable(),$action->id);
        return ['recommendation'=>$payload,'action_id'=>$action->id,'action_status'=>$action->status->value,'replayed'=>false];
    }

    public function startReDiagnostic(string $organizationId,string $sourceSessionId,string $actorId):array
    {
        $source=$this->runtime->get($organizationId,$sourceSessionId)??throw new DomainException('Source diagnostic runtime was not found.');
        $base=$this->sessions->get($organizationId,$sourceSessionId)??throw new DomainException('Source diagnostic session was not found.');
        $input=['pack_id'=>$source['state']['pack_id'],'methodology_version'=>$source['state']['methodology_version'],'pack_version'=>$base->packVersion(),'domain'=>$base->target()->domain,'subject_type'=>$base->target()->subjectType,'subject_id'=>$base->target()->subjectId,'mode'=>$source['mode'],'parent_session_id'=>$sourceSessionId];
        $followup=$this->start($organizationId,$input,$actorId);
        $pending=$this->runtime->pendingScheduleForSource($organizationId,$sourceSessionId);
        if($pending!==null)$this->runtime->markScheduleStarted($organizationId,(string)$pending['schedule_id'],(string)$followup['session_id'],new DateTimeImmutable());
        return $followup;
    }

    public function comparison(string $organizationId,string $beforeSessionId,string $afterSessionId):array
    {
        $before=$this->report($organizationId,$beforeSessionId)['report'];
        $after=$this->report($organizationId,$afterSessionId)['report'];
        $beforeScore=(float)($before['overallHealth']??0); $afterScore=(float)($after['overallHealth']??0);
        $beforeFindings=$this->ids($before['findings']??[]); $afterFindings=$this->ids($after['findings']??[]);
        return ['before'=>$beforeSessionId,'after'=>$afterSessionId,'status'=>abs($afterScore-$beforeScore)<.01?'UNCHANGED':($afterScore>$beforeScore?'IMPROVED':'WORSENED'),'health_delta'=>round($afterScore-$beforeScore,2),'coverage_delta'=>round((float)($after['coverage']??0)-(float)($before['coverage']??0),4),'resolved_findings'=>array_values(array_diff($beforeFindings,$afterFindings)),'new_findings'=>array_values(array_diff($afterFindings,$beforeFindings))];
    }

    private function materializeInputs(string $o,string $s,array $state,array $existing,DateTimeImmutable $now,string $actor):void
    {
        $ids=array_fill_keys(array_map(fn($r)=>$r->id,$existing),true);
        foreach($state['facts']??[] as $code=>$fact){
            $id='runtime:fact:'.$code; if(isset($ids[$id]))continue; $e=array_values($fact['evidence_ids']??[]); if($e===[])continue;
            $this->recordResult->execute($o,$s,new DiagnosticRecord($id,DiagnosticRecordType::Fact,(string)$code,'Interview-derived fact: '.$code,$fact['value']??null,null,$e,[],$now),'USER',$actor);
        }
        foreach($state['metrics']??[] as $code=>$metric){
            $id='runtime:metric:'.$code; if(isset($ids[$id])||!is_numeric($metric['value']??null))continue; $e=array_values($metric['evidence_ids']??[]); if($e===[])continue;
            $this->recordResult->execute($o,$s,new DiagnosticRecord($id,DiagnosticRecordType::Metric,(string)$code,'Interview-derived metric: '.$code,(float)$metric['value'],null,$e,[],$now),'USER',$actor);
        }
    }

    private function state(string $o,string $s,array $row,?CompiledDiagnosticPack $pack=null):DiagnosticState
    {
        $session=$this->sessions->get($o,$s)??throw new DomainException('Diagnostic session was not found.');
        return $this->stateFromRuntime($s,$pack??$this->compiled($o,$row),$session->evidence(),$row['state']);
    }

    private function stateFromRuntime(string $sessionId,CompiledDiagnosticPack $pack,array $evidence,array $runtime):DiagnosticState
    {
        $facts=$this->facts($sessionId,$runtime); $factInput=[];$metricInput=[];$byEvidence=[];
        foreach($evidence as $e)$byEvidence[$e->id]=$e;
        foreach($facts as $fact){
            if($fact->status!==FactStatus::Known)continue; $signals=[];
            foreach($fact->evidenceIds as $id)if(isset($byEvidence[$id]))$signals[]=new EvidenceSignal($id,$byEvidence[$id]->type->value,$byEvidence[$id]->reliability??.6,$byEvidence[$id]->directness??.8,$byEvidence[$id]->capturedAt,$fact->value);
            $factInput[$fact->key]=new ObservedValue($fact->value,$signals);
        }
        foreach($runtime['metrics']??[] as $code=>$metric){
            if(!is_numeric($metric['value']??null))continue; $signals=[];
            foreach($metric['evidence_ids']??[] as $id)if(isset($byEvidence[$id]))$signals[]=new EvidenceSignal($id,$byEvidence[$id]->type->value,.7,.8,$byEvidence[$id]->capturedAt,(float)$metric['value']);
            $metricInput[$code]=new ObservedValue((float)$metric['value'],$signals);
        }
        $result=$this->engine->evaluate(new DiagnosticInput($factInput,$metricInput,new DateTimeImmutable()),$pack->pack);
        return $this->states->build($sessionId,$pack,$facts,$evidence,$result,[],[],[],(int)($runtime['revision']??0)+1);
    }

    private function facts(string $sessionId,array $runtime):array
    {
        $facts=[];
        foreach($runtime['facts']??[] as $key=>$f){
            $at=new DateTimeImmutable((string)($f['updated_at']??'now'));
            $facts[]=new Fact(substr(hash('sha256',$sessionId.':'.$key),0,32),$sessionId,(string)$key,$f['value']??null,(string)($f['value_type']??get_debug_type($f['value']??null)),FactStatus::Known,(float)($f['confidence']??.6),(string)($f['source']??'INTERVIEW'),array_values($f['evidence_ids']??[]),$at,$at);
        }
        return $facts;
    }

    private function compiled(string $o,array $row):CompiledDiagnosticPack{return $this->methodology->compile($o,(string)$row['state']['pack_id'],(string)$row['state']['methodology_version']);}
    private function nextDecision(array $row,DiagnosticState $state,?CompiledDiagnosticPack $pack=null):?object{return $this->questions->decide($state,$pack??$this->compiled((string)$row['organization_id'],$row),$row['state']['history']??[],DiagnosticMode::from((string)$row['mode']),(int)($row['state']['remaining_questions']??0),(int)($row['state']['remaining_minutes']??0));}
    private function decisionFor(string $id,CompiledDiagnosticPack $pack,DiagnosticState $state,array $row):?object
    {
        $q=$pack->questionsById[$id]??null; if($q===null)return $this->nextDecision($row,$state,$pack);
        return new \Domains\Diagnostic\Interview\NextQuestionDecision($q->id,$q->text,1.0,'Resume current question.',$q->targetCriteria,$q->targetFacts,1.0,1.0);
    }

    private function snapshot(array $row,object $session,DiagnosticState $state,?object $next):array
    {
        return ['session_id'=>$session->id(),'status'=>$row['status'],'mode'=>$row['mode'],'target'=>$session->target()->toArray(),'pack_id'=>$session->packId(),'pack_version'=>$session->packVersion(),'methodology_version'=>$row['state']['methodology_version'],'revision'=>(int)$row['state_revision'],'coverage'=>$state->coverage['pack']??0,'confidence'=>$state->confidence,'score'=>$state->scores['pack']??null,'known_facts'=>array_keys($state->knownFacts),'missing_facts'=>$state->missingFacts,'contradictions'=>$row['state']['contradictions']??[],'next_question'=>$next===null?null:$this->questionArray($next)];
    }
    private function questionArray(object $q):array{return ['id'=>$q->questionId,'text'=>$q->question,'priority'=>$q->priorityScore,'reason'=>$q->reason,'criteria'=>$q->criterionIds,'target_facts'=>$q->targetFactIds];}

    private function recommendationTemplates(CompiledDiagnosticPack $pack):array
    {
        $out=[]; foreach($pack->recommendationsById as $r)$out[]=['id'=>$r->id,'finding_ids'=>$r->triggerRules,'root_cause_ids'=>[],'rationale'=>$r->rationale,'actions'=>$r->actions,'success_metrics'=>$r->successMetrics,'expected_impact'=>$r->expectedImpact,'effort'=>$r->implementationEffort,'owner_role'=>$r->ownerRole,'dependencies'=>$r->dependencies,'expected_outcome'=>$r->description,'priority_inputs'=>['impact'=>$this->level($r->priority),'confidence'=>.8,'urgency'=>$this->level($r->priority),'effort'=>1-$this->level($r->implementationEffort),'cost'=>1-$this->level($r->costLevel),'time'=>$r->implementationTime===null?.5:.7,'risk'=>.3]];
        return $out;
    }
    private function level(string $v):float{return match(strtolower($v)){'critical','very_high'=>1.0,'high'=>.85,'medium','moderate'=>.55,'low'=>.25,'small'=>.2,'large'=>.9,default=>.5};}
    private function recommendationArray(Recommendation $r):array{return ['id'=>$r->id,'related_findings'=>$r->relatedFindings,'related_root_causes'=>$r->relatedRootCauses,'priority'=>$r->priority,'impact'=>$r->impact,'effort'=>$r->effort,'rationale'=>$r->rationale,'actions'=>$r->actions,'success_metrics'=>$r->successMetrics,'status'=>$r->status->value,'priority_score'=>$r->priorityScore,'details'=>$r->details];}
    private function recommendationFromArray(array $p):Recommendation{return new Recommendation((string)$p['id'],array_values($p['related_findings']??[]),array_values($p['related_root_causes']??[]),(string)($p['priority']??'MEDIUM'),(string)($p['impact']??'UNKNOWN'),(string)($p['effort']??'medium'),(string)$p['rationale'],array_values($p['actions']??[]),array_values($p['success_metrics']??[]),RecommendationStatus::from((string)($p['status']??RecommendationStatus::Proposed->value)),(float)($p['priority_score']??0),is_array($p['details']??null)?$p['details']:[]);}
    private function ids(array $xs):array{$ids=[];foreach($xs as $x){if(is_array($x))$ids[]=(string)($x['id']??$x['ruleId']??$x['rule_id']??'');}return array_values(array_filter($ids));}
}
