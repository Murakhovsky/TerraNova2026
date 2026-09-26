<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\AI\GrowthResponseClassificationPrompt;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementResponseRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthResponseClassificationGatewayInterface;
use Domains\Growth\Application\DTO\GrowthResponseClassificationDraft;
use Domains\Growth\Application\Service\GrowthEngagementResponseService;
use Domains\Growth\Application\Service\GrowthResponseClassificationService;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use Domains\Growth\Domain\GrowthOutcomeType;
use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;
use Domains\Growth\Domain\GrowthResponseSentiment;
use Domains\Growth\Domain\GrowthResponseUrgency;
use Domains\Growth\Domain\OpportunityCandidate;
use Domains\Growth\Domain\Signal;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

function expectGrowthV0450(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$executions=new class implements GrowthEngagementExecutionRepositoryInterface {
    public int $locks=0;
    public array $rows=[
        'ACT-EMAIL'=>[
            'organization_id'=>'org-1','execution_id'=>'EXE-1','candidate_id'=>'CAND-0001','recommendation_id'=>'REC-0001',
            'target_domain'=>'growth','target_reference_type'=>'growth_contact','target_reference_id'=>'GCNT-1',
            'action_id'=>'ACT-EMAIL','action_type'=>'growth.send_message','channel'=>'email','payload_fingerprint'=>'x',
        ],
        'ACT-SALES'=>[
            'organization_id'=>'org-1','execution_id'=>'EXE-2','candidate_id'=>'CAND-0001','recommendation_id'=>'REC-0002',
            'target_domain'=>'sales','target_reference_type'=>'sales_deal','target_reference_id'=>'42',
            'action_id'=>'ACT-SALES','action_type'=>'sales.send_message','channel'=>'email','payload_fingerprint'=>'y',
        ],
    ];
    public function byRecommendation(string $organizationId,string $recommendationId):?array{
        foreach($this->rows as $row)if($row['recommendation_id']===$recommendationId)return $row;
        return null;
    }
    public function byActionId(string $organizationId,string $actionId):?array{return $this->rows[$actionId]??null;}
    public function createOrVerify(string $organizationId,string $executionId,string $candidateId,string $recommendationId,string $targetDomain,string $targetReferenceType,string $targetReferenceId,string $actionId,string $actionType,string $channel,string $payloadFingerprint,int $actorId):void{}
    public function latestForCandidate(string $organizationId,string $candidateId):?array{return null;}
    public function lockPreHandoffCapacity(string $organizationId):void{$this->locks++;}
    public function countPreHandoffSince(string $organizationId,string $since):int{return 0;}
    public function countPreHandoffSinceByChannel(string $organizationId,string $channel,string $since):int{return 0;}
    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array{return null;}
};

$responses=new class implements GrowthEngagementResponseRepositoryInterface {
    public array $rows=[];
    public array $classifications=[];
    public function recordOrVerify(array $response):array{
        $key=$response['source_event_id'];
        if(isset($this->rows[$key])){
            foreach(['execution_id','candidate_id','recommendation_id','action_id','channel','body_hash','provider_reference','thread_reference','occurred_at'] as $field){
                if((string)($this->rows[$key][$field]??'')!==(string)($response[$field]??'')){
                    throw new InvalidArgumentException('Growth response source event conflicts with an existing observation.');
                }
            }
            return $this->rows[$key]+['replayed'=>true];
        }
        return $this->rows[$key]=$response+['replayed'=>false];
    }
    public function byId(string $organizationId,string $responseId):?array{
        foreach($this->rows as $row)if(($row['response_id']??null)===$responseId)return $row;
        return null;
    }
    public function lockById(string $organizationId,string $responseId):array{
        return $this->byId($organizationId,$responseId)??throw new InvalidArgumentException('not found');
    }
    public function latestForCandidate(string $organizationId,string $candidateId,int $limit=20):array{
        return array_values(array_filter($this->rows,static fn(array $row):bool=>$row['candidate_id']===$candidateId));
    }
    public function latestClassification(string $organizationId,string $responseId):?array{
        $rows=array_values(array_filter($this->classifications,static fn(array $row):bool=>$row['response_id']===$responseId));
        usort($rows,static fn(array $a,array $b):int=>$b['revision']<=>$a['revision']);
        return $rows[0]??null;
    }
    public function classificationForVersion(string $organizationId,string $responseId,string $promptVersion,string $schemaVersion):?array{
        foreach($this->classifications as $row){
            if($row['response_id']===$responseId&&$row['prompt_version']===$promptVersion&&$row['schema_version']===$schemaVersion)return $row;
        }
        return null;
    }
    public function appendClassification(array $classification):void{$this->classifications[]=$classification;}
};

$learning=new class implements GrowthLearningRepositoryInterface {
    public array $outcomes=[];
    public function bindExternalSubject(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,string $sourceEventId):void{}
    public function candidateByExternalSubject(string $organizationId,string $sourceDomain,string $referenceType,string $referenceId):?string{return null;}
    public function externalSubjectsForCandidate(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType):array{return [];}
    public function recordOutcome(GrowthOutcomeObservation $outcome):void{$this->outcomes[]=$outcome;}
    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array{return [];}
    public function outcomeSummary(string $organizationId,string $candidateId):array{return [];}
};

$transactions=new class implements TransactionManagerInterface {
    private bool $active=false;
    public function transactional(callable $operation):mixed{
        $previous=$this->active;$this->active=true;
        try{return $operation();}finally{$this->active=$previous;}
    }
    public function isActive():bool{return $this->active;}
    public function afterCommit(callable $callback):void{$callback();}
};

$eventStore=new class implements EventStoreInterface {
    public array $events=[];
    public function append(DomainEvent $event):void{$this->events[]=$event;}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};

$audit=new class implements AuditRepositoryInterface {
    public array $entries=[];
    public function append(AuditEntry $entry):void{$this->entries[]=$entry;}
};

$events=new EventBus($eventStore,$transactions);
$ingestion=new GrowthEngagementResponseService($executions,$responses,$learning,$transactions,$events,$audit);

$result=$ingestion->recordExternalResponse(
    'org-1','corr-1','reply-event-1','ACT-EMAIL','email',
    'Thanks. Can we meet on Tuesday?','2026-09-26T13:00:00+03:00','provider-msg-1','thread-1'
);
expectGrowthV0450(($result['channel']??null)==='email','Inbound email response was not recorded.');
expectGrowthV0450(($result['occurred_at']??null)==='2026-09-26T10:00:00+00:00','Response timestamp must normalize to UTC.');
expectGrowthV0450($executions->locks===1,'Inbound response must serialize on tenant admission lock.');
expectGrowthV0450(count($learning->outcomes)===1,'Inbound response must immediately create one Growth outcome.');
expectGrowthV0450($learning->outcomes[0]->outcomeType===GrowthOutcomeType::ReplyReceived,'Inbound response must record reply_received.');
expectGrowthV0450(count($eventStore->events)===2,'Inbound response must emit response + outcome events.');
expectGrowthV0450($eventStore->events[0]->type===GrowthEventType::ENGAGEMENT_RESPONSE_RECEIVED,'Response event missing.');
expectGrowthV0450($eventStore->events[1]->type===GrowthEventType::OUTCOME_RECORDED,'Outcome event missing.');
expectGrowthV0450(!array_key_exists('body',$eventStore->events[0]->payload),'Raw response body must not leak into Event payload.');
expectGrowthV0450(count($audit->entries)===1,'Inbound response must append one Audit entry.');

$replayed=$ingestion->recordExternalResponse(
    'org-1','corr-2','reply-event-1','ACT-EMAIL','email',
    'Thanks. Can we meet on Tuesday?','2026-09-26T13:00:00+03:00','provider-msg-1','thread-1'
);
expectGrowthV0450(($replayed['replayed']??false)===true,'Provider retry must replay idempotently.');
expectGrowthV0450(count($learning->outcomes)===1,'Provider retry must not duplicate reply outcome.');
expectGrowthV0450(count($eventStore->events)===2,'Provider retry must not duplicate events.');

$conflict=false;
try{
    $ingestion->recordExternalResponse(
        'org-1','corr-3','reply-event-1','ACT-EMAIL','email',
        'Different payload under reused provider event id.','2026-09-26T13:01:00+03:00',null,null
    );
}catch(InvalidArgumentException){$conflict=true;}
expectGrowthV0450($conflict,'Reused response source event with changed payload must conflict.');

$rejected=false;
try{
    $ingestion->recordExternalResponse(
        'org-1','corr-4','reply-event-sales','ACT-SALES','email',
        'This belongs to Sales now.','2026-09-26T13:02:00+03:00',null,null
    );
}catch(InvalidArgumentException){$rejected=true;}
expectGrowthV0450($rejected,'Growth response ingress must reject Sales-owned execution.');

$growth=new class implements GrowthRepositoryInterface {
    public function createSignal(Signal $signal,int $actorId):void{}
    public function viewSignal(string $organizationId,string $signalId):?array{return null;}
    public function createCandidate(OpportunityCandidate $candidate,int $actorId):void{}
    public function lockCandidate(string $organizationId,string $candidateId):OpportunityCandidate{throw new RuntimeException('unused');}
    public function updateCandidate(OpportunityCandidate $candidate,int $actorId):void{}
    public function viewCandidate(string $organizationId,string $candidateId):?array{
        return ['candidate_id'=>$candidateId,'opportunity_type'=>'new_business','growth_mode'=>'acquire','target_domain'=>'sales'];
    }
    public function listSignalsBySubject(string $organizationId,string $subjectType,string $subjectId,int $limit=20):array{return [];}
    public function listCandidatesBySubject(string $organizationId,string $subjectType,string $subjectId,int $limit=20):array{return [];}
};

$engagement=new class implements GrowthEngagementRepositoryInterface {
    public function createRun(string $organizationId,string $runId,string $candidateId,array $contextSnapshot,string $promptVersion,string $schemaVersion,int $actorId):void{}
    public function completeRun(string $organizationId,string $runId,string $recommendationId,string $provider,string $model,?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency):void{}
    public function failRun(string $organizationId,string $runId,string $errorSummary):void{}
    public function viewRun(string $organizationId,string $runId):?array{return null;}
    public function supersedeProposedForCandidate(string $organizationId,string $candidateId,string $reason,int $actorId):array{return [];}
    public function createRecommendation(EngagementRecommendation $recommendation,string $runId,int $actorId):void{}
    public function lockRecommendation(string $organizationId,string $recommendationId):EngagementRecommendation{throw new RuntimeException('unused');}
    public function updateRecommendation(EngagementRecommendation $recommendation,int $actorId):void{}
    public function viewRecommendation(string $organizationId,string $recommendationId):?array{
        return ['recommendation_id'=>$recommendationId,'action_type'=>'send_message','message_angle'=>'Discuss operational fit.'];
    }
    public function latestRecommendation(string $organizationId,string $candidateId):?array{return null;}
};

$classifierGateway=new class implements GrowthResponseClassificationGatewayInterface {
    public int $calls=0;
    public array $contexts=[];
    public function classify(string $organizationId,string $correlationId,array $context):GrowthResponseClassificationDraft{
        $this->calls++;$this->contexts[]=$context;
        return new GrowthResponseClassificationDraft(
            GrowthResponseIntent::MeetingRequest,
            GrowthResponseSentiment::Positive,
            GrowthResponseUrgency::Normal,
            'The respondent asks to schedule a meeting.',
            'Schedule a meeting on Tuesday.',
            GrowthResponseNextOwner::Sales,
            0.97,
            'test-provider','test-model',
            GrowthResponseClassificationPrompt::PROMPT_VERSION,
            GrowthResponseClassificationPrompt::SCHEMA_VERSION,
        );
    }
};

$classifier=new GrowthResponseClassificationService(
    $responses,$growth,$engagement,$classifierGateway,$transactions,$events,$audit
);
$responseId=(string)$result['response_id'];
$classified=$classifier->classifyResponse('org-1',$responseId,'corr-classify-1');
expectGrowthV0450(($classified['intent']??null)==='meeting_request','Response intent classification failed.');
expectGrowthV0450(($classified['recommended_next_owner']??null)==='sales','Response owner recommendation failed.');
expectGrowthV0450($classifierGateway->calls===1,'Classifier gateway must be called once for a new version.');
expectGrowthV0450(($classifierGateway->contexts[0]['response']['body']??null)==='Thanks. Can we meet on Tuesday?','Classifier must receive observed response text.');
expectGrowthV0450(!isset($classifierGateway->contexts[0]['response']['action_id']),'Classifier context must not expose internal Action ids.');

$classifierReplay=$classifier->classifyResponse('org-1',$responseId,'corr-classify-2');
expectGrowthV0450(($classifierReplay['replayed']??false)===true,'Same prompt/schema classification must replay.');
expectGrowthV0450($classifierGateway->calls===1,'Classification replay must not spend another LLM call.');
expectGrowthV0450(count($responses->classifications)===1,'Classification replay must not create another revision.');

echo "Growth V0.45 Inbound Responses contracts passed.\n";
