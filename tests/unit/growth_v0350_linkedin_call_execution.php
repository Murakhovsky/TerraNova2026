<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthActionProposalGatewayInterface;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementDeliveryRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementActivationProviderInterface;
use Domains\Growth\Application\Contract\GrowthEngagementExecutionRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthEngagementRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthExternalEngagementGatewayInterface;
use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthMutationReceiptInterface;
use Domains\Growth\Application\Contract\GrowthOutreachSequenceGuardInterface;
use Domains\Growth\Application\Contract\GrowthOutboundMessageGatewayInterface;
use Domains\Growth\Application\DTO\GrowthExecutionAction;
use Domains\Growth\Application\DTO\GrowthExternalEngagementDelivery;
use Domains\Growth\Application\DTO\GrowthOutboundDelivery;
use Domains\Growth\Application\Service\GrowthEngagementExecutionService;
use Domains\Growth\Automation\Action\GrowthCallHandler;
use Domains\Growth\Automation\Action\GrowthLinkedInHandler;
use Domains\Growth\Automation\Action\GrowthSendMessageHandler;
use Domains\Growth\Automation\Policy\GrowthPolicyCatalog;
use Domains\Growth\Bootstrap\GrowthDomainModule;
use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Application\Contract\GrowthEngagementLimitProviderInterface;
use Domains\Growth\Domain\EngagementExecutionLimitPolicy;
use Domains\Growth\Domain\EngagementActivationMode;
use Domains\Growth\Domain\EngagementRecommendation;
use Domains\Growth\Domain\GrowthContact;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use Domains\Growth\Infrastructure\Integration\N8nGrowthExternalEngagementGateway;
use Kernel\Action\Action;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Policy\PolicyDecision;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Platform\Integration\Contract\IntegrationOutboxInterface;

function expectGrowthV0350(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$contacts=new class implements GrowthBuyingCommitteeRepositoryInterface {
    /** @var array<string,array<string,mixed>> */
    public array $contacts=[
        'contact-linkedin'=>[
            'organization_id'=>'org-1','contact_id'=>'contact-linkedin','full_name'=>'Ada Prospect',
            'identity_type'=>'linkedin','identity_value'=>'https://www.linkedin.com/in/ada-prospect','source_references'=>['fixture'],
        ],
        'contact-phone'=>[
            'organization_id'=>'org-1','contact_id'=>'contact-phone','full_name'=>'Lin Prospect',
            'identity_type'=>'phone','identity_value'=>'+380501234567','source_references'=>['fixture'],
        ],
        'contact-email'=>[
            'organization_id'=>'org-1','contact_id'=>'contact-email','full_name'=>'Email Prospect',
            'identity_type'=>'email','identity_value'=>'email@example.test','source_references'=>['fixture'],
        ],
    ];
    public function createContact(GrowthContact $contact,int $actorId):void{}
    public function findContactByIdentity(string $organizationId,string $identityType,string $identityValue):?array{return null;}
    public function viewContact(string $organizationId,string $contactId):?array{return $this->contacts[$contactId]??null;}
    public function linkContactToAccount(string $organizationId,string $accountId,string $contactId,int $actorId):void{}
    public function isContactLinked(string $organizationId,string $accountId,string $contactId):bool{return true;}
    public function createContactSnapshot(ContactSnapshot $snapshot,int $actorId):void{}
    public function viewContactSnapshot(string $organizationId,string $snapshotId):?ContactSnapshot{return null;}
    public function latestContactSnapshotsForAccount(string $organizationId,string $accountId):array{return [];}
    public function accountContacts(string $organizationId,string $accountId):array{return array_values($this->contacts);}
    public function createAssessment(string $assessmentId,string $organizationId,BuyingCommitteeAssessment $assessment,int $actorId):void{}
    public function viewAssessment(string $organizationId,string $assessmentId):?array{return null;}
    public function latestAssessment(string $organizationId,string $accountId):?array{return null;}
};

$external=new class implements GrowthExternalEngagementGatewayInterface {
    /** @var list<array<string,mixed>> */
    public array $queued=[];
    public function queueLinkedIn(
        string $organizationId,string $recipientProfile,?string $recipientName,string $body,
        string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthExternalEngagementDelivery {
        $this->queued[]=['channel'=>'linkedin','recipient'=>$recipientProfile,'idempotency_key'=>$idempotencyKey,'metadata'=>$metadata];
        return new GrowthExternalEngagementDelivery('OUT-1','queued',null);
    }
    public function queueCall(
        string $organizationId,string $recipientPhone,?string $recipientName,string $callBrief,
        string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthExternalEngagementDelivery {
        $this->queued[]=['channel'=>'phone','recipient'=>$recipientPhone,'idempotency_key'=>$idempotencyKey,'metadata'=>$metadata];
        return new GrowthExternalEngagementDelivery('OUT-2','queued',null);
    }
};

$linkedinHandler=new GrowthLinkedInHandler($contacts,$external);
$linkedinAction=new Action(
    'ACT-LI','org-1',GrowthLinkedInHandler::TYPE,'growth_contact','contact-linkedin',
    ['channel'=>'linkedin','body'=>'Short approved LinkedIn note','growth_candidate_id'=>'cand-linkedin','growth_recommendation_id'=>'rec-linkedin'],
    'GROWTH','rec-linkedin','APPROVAL_REQUIRED','MEDIUM','growth-linkedin-key',
    new DateTimeImmutable('2026-09-24T09:00:00+00:00'),correlationId:'corr-li',
);
$linkedinResult=$linkedinHandler->execute($linkedinAction);
expectGrowthV0350($linkedinResult->successful,'Approved LinkedIn Action must queue through external engagement gateway.');
expectGrowthV0350(($external->queued[0]['recipient']??null)==='https://www.linkedin.com/in/ada-prospect','LinkedIn identity must resolve at execution time.');
expectGrowthV0350(($external->queued[0]['idempotency_key']??null)==='growth-linkedin-key','LinkedIn idempotency must propagate.');

$callHandler=new GrowthCallHandler($contacts,$external);
$callAction=new Action(
    'ACT-CALL','org-1',GrowthCallHandler::TYPE,'growth_contact','contact-phone',
    ['channel'=>'phone','body'=>'Call brief: confirm timing and offer diagnostic.','growth_candidate_id'=>'cand-call','growth_recommendation_id'=>'rec-call'],
    'GROWTH','rec-call','APPROVAL_REQUIRED','MEDIUM','growth-call-key',
    new DateTimeImmutable('2026-09-24T09:05:00+00:00'),correlationId:'corr-call',
);
$callResult=$callHandler->execute($callAction);
expectGrowthV0350($callResult->successful,'Approved call Action must queue through external engagement gateway.');
expectGrowthV0350(($external->queued[1]['recipient']??null)==='+380501234567','Phone identity must resolve at execution time.');

$wrongIdentity=new Action(
    'ACT-BAD','org-1',GrowthCallHandler::TYPE,'growth_contact','contact-email',
    ['channel'=>'phone','body'=>'Call brief'],
    'GROWTH','rec-bad','APPROVAL_REQUIRED','MEDIUM','growth-call-bad',
    new DateTimeImmutable('2026-09-24T09:10:00+00:00'),
);
expectGrowthV0350(!$callHandler->execute($wrongIdentity)->successful,'Call Action must reject a non-phone contact identity.');

$platformOutbox=new class implements IntegrationOutboxInterface {
    /** @var list<array<string,mixed>> */
    public array $items=[];
    public function enqueue(
        string $integration,string $eventType,string $entityType,?int $entityId,array $payload,string $dedupeKey
    ):string {
        $this->items[]=compact('integration','eventType','entityType','entityId','payload','dedupeKey');
        return 'tn-outbox-77';
    }
};
$n8n=new N8nGrowthExternalEngagementGateway($platformOutbox);
$queued=$n8n->queueLinkedIn(
    'org-1','https://www.linkedin.com/in/ada-prospect','Ada','Approved body','corr-n8n','idem-n8n',
    ['kernel_action_id'=>'ACT-LI'],
);
expectGrowthV0350($queued->status==='queued'&&$queued->deliveryId==='tn-outbox-77','n8n adapter must return durable queued delivery.');
expectGrowthV0350(($platformOutbox->items[0]['eventType']??null)==='growth.engagement.linkedin','LinkedIn n8n event type is wrong.');
expectGrowthV0350(($platformOutbox->items[0]['payload']['organization_id']??null)==='org-1','Growth tenant identity must remain in integration envelope.');

$phone=new GrowthContact(
    'phone-1',OrganizationId::fromString('org-1'),'Phone Prospect','phone','+380501234567',['fixture'],
);
expectGrowthV0350($phone->normalizedIdentityValue()==='+380501234567','Growth phone identity normalization failed.');

$engagement=new class implements GrowthEngagementRepositoryInterface {
    public array $recommendations=[
        'rec-linkedin'=>[
            'recommendation_id'=>'rec-linkedin','candidate_id'=>'cand-linkedin','status'=>'accepted',
            'action_type'=>'connect_linkedin','channel'=>'linkedin','contact_id'=>'contact-linkedin','confidence'=>0.8,
        ],
        'rec-call'=>[
            'recommendation_id'=>'rec-call','candidate_id'=>'cand-call','status'=>'accepted',
            'action_type'=>'call','channel'=>'phone','contact_id'=>'contact-phone','confidence'=>0.9,
        ],
    ];
    public function createRun(string $organizationId,string $runId,string $candidateId,array $contextSnapshot,string $promptVersion,string $schemaVersion,int $actorId):void{}
    public function completeRun(string $organizationId,string $runId,string $recommendationId,string $provider,string $model,?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency):void{}
    public function failRun(string $organizationId,string $runId,string $errorSummary):void{}
    public function viewRun(string $organizationId,string $runId):?array{return null;}
    public function supersedeProposedForCandidate(string $organizationId,string $candidateId,string $reason,int $actorId):array{return [];}
    public function createRecommendation(EngagementRecommendation $recommendation,string $runId,int $actorId):void{}
    public function lockRecommendation(string $organizationId,string $recommendationId):EngagementRecommendation{throw new InvalidArgumentException('unused');}
    public function updateRecommendation(EngagementRecommendation $recommendation,int $actorId):void{}
    public function viewRecommendation(string $organizationId,string $recommendationId):?array{return $this->recommendations[$recommendationId]??null;}
    public function latestRecommendation(string $organizationId,string $candidateId):?array{return null;}
};
$learning=new class implements GrowthLearningRepositoryInterface {
    public array $deals=[];
    public function bindExternalSubject(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,string $sourceEventId):void{}
    public function candidateByExternalSubject(string $organizationId,string $sourceDomain,string $referenceType,string $referenceId):?string{return null;}
    public function externalSubjectsForCandidate(string $organizationId,string $candidateId,string $sourceDomain,string $referenceType):array{return $this->deals[$candidateId]??[];}
    public function recordOutcome(GrowthOutcomeObservation $outcome):void{}
    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array{return [];}
    public function outcomeSummary(string $organizationId,string $candidateId):array{return [];}
};
$executions=new class implements GrowthEngagementExecutionRepositoryInterface {
    public function byRecommendation(string $organizationId,string $recommendationId):?array{return null;}
    public function byActionId(string $organizationId,string $actionId):?array{return null;}
    public function createOrVerify(string $organizationId,string $executionId,string $candidateId,string $recommendationId,string $targetDomain,string $targetReferenceType,string $targetReferenceId,string $actionId,string $actionType,string $channel,string $payloadFingerprint,int $actorId):void{}
    public function latestForCandidate(string $organizationId,string $candidateId):?array{return null;}
    public function lockPreHandoffCapacity(string $organizationId):void{}
    public function countPreHandoffSince(string $organizationId,string $since):int{return 0;}
    public function countPreHandoffSinceByChannel(string $organizationId,string $channel,string $since):int{return 0;}
    public function latestPreHandoffForTarget(string $organizationId,string $targetReferenceId):?array{return null;}
};
$deliveries=new class implements GrowthEngagementDeliveryRepositoryInterface {
    public function recordOrVerify(array $observation):array{return $observation+['replayed'=>false];}
    public function latestForExecution(string $organizationId,string $executionId):?array{return null;}
    public function forExecution(string $organizationId,string $executionId,int $limit=20):array{return [];}
};
$limitProvider=new class implements GrowthEngagementLimitProviderInterface {
    public function policyFor(string $organizationId):EngagementExecutionLimitPolicy{return new EngagementExecutionLimitPolicy(50,24);}
};
$activationProvider=new class implements GrowthEngagementActivationProviderInterface {
    public function modeFor(string $organizationId,string $channel):EngagementActivationMode{return EngagementActivationMode::ApprovalRequired;}
};
$receipts=new class implements GrowthMutationReceiptInterface {
    public function claim(string $organizationId,string $operation,string $idempotencyKey,string $fingerprint):bool{return true;}
};
$actionGateway=new class implements GrowthActionProposalGatewayInterface {
    public function proposeSalesMessage(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $dealId,string $channel,string $body,?float $confidence,string $kernelIdempotencyKey):GrowthExecutionAction{throw new InvalidArgumentException('unused');}
    public function proposeGrowthMessage(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $contactId,string $channel,string $body,?float $confidence,string $kernelIdempotencyKey):GrowthExecutionAction{throw new InvalidArgumentException('unused');}
    public function proposeGrowthLinkedIn(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $contactId,string $body,?float $confidence,string $kernelIdempotencyKey):GrowthExecutionAction{throw new InvalidArgumentException('unused');}
    public function proposeGrowthCall(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $recommendationId,string $contactId,string $callBrief,?float $confidence,string $kernelIdempotencyKey):GrowthExecutionAction{throw new InvalidArgumentException('unused');}
    public function find(string $organizationId,string $actionId):?GrowthExecutionAction{return null;}
};
$sequenceGuard=new class implements GrowthOutreachSequenceGuardInterface {
    public function bootstrapBlock(string $organizationId,array $recommendation,DateTimeImmutable $startedAt):?array{return null;}
    public function hardBlockForRecommendation(string $organizationId,array $recommendation):?array{return null;}
    public function blockingForRecommendation(string $organizationId,array $recommendation):?array{return null;}
};
$transactions=new class implements TransactionManagerInterface {
    public function transactional(callable $operation):mixed{return $operation();}
    public function isActive():bool{return false;}
    public function afterCommit(callable $callback):void{$callback();}
};
$eventStore=new class implements EventStoreInterface {
    public function append(DomainEvent $event):void{}
    public function find(string $eventId):?DomainEvent{return null;}
    public function findByAggregate(string $organizationId,string $aggregateType,string $aggregateId,int $limit=100):array{return [];}
};
$audit=new class implements AuditRepositoryInterface {
    public function append(AuditEntry $entry):void{}
};
$executionService=new GrowthEngagementExecutionService(
    $engagement,$learning,$contacts,$executions,$deliveries,$limitProvider,$activationProvider,$sequenceGuard,$receipts,$actionGateway,$transactions,new EventBus($eventStore,$transactions),$audit,
);
$linkedinBrief=$executionService->executionBrief('org-1','cand-linkedin','rec-linkedin');
$callBrief=$executionService->executionBrief('org-1','cand-call','rec-call');
expectGrowthV0350(($linkedinBrief['eligibility']['action_type']??null)===GrowthLinkedInHandler::TYPE,'LinkedIn recommendation must route to growth.send_linkedin.');
expectGrowthV0350(($callBrief['eligibility']['action_type']??null)===GrowthCallHandler::TYPE,'Phone recommendation must route to growth.place_call.');

$learning->deals['cand-call']=['deal-1'];
$postHandoffCall=$executionService->executionBrief('org-1','cand-call','rec-call');
expectGrowthV0350(($postHandoffCall['eligibility']['code']??null)==='post_handoff_call_not_supported','Post-handoff call must remain Sales-owned.');

$emailOutbound=new class implements GrowthOutboundMessageGatewayInterface {
    public function queueEmail(string $organizationId,string $recipientAddress,?string $recipientName,string $body,?string $subject,string $locale,string $correlationId,string $idempotencyKey,array $metadata=[]):GrowthOutboundDelivery{
        return new GrowthOutboundDelivery('N-1','D-1','queued',null);
    }
};
$emailHandler=new GrowthSendMessageHandler($contacts,$emailOutbound);
$policies=(new GrowthPolicyCatalog())->policies('org-1');
$policyByType=[];
foreach($policies as $policy)$policyByType[$policy->actionType]=$policy;
foreach([GrowthSendMessageHandler::TYPE,GrowthLinkedInHandler::TYPE,GrowthCallHandler::TYPE] as $type){
    expectGrowthV0350(isset($policyByType[$type]),'Missing Growth execution policy for '.$type);
    expectGrowthV0350($policyByType[$type]->decision===PolicyDecision::ApprovalRequired,'Growth execution must remain approval-required for '.$type);
}
$module=new GrowthDomainModule($emailHandler,$linkedinHandler,$callHandler);
expectGrowthV0350($module->actionTypes()===[GrowthSendMessageHandler::TYPE,GrowthLinkedInHandler::TYPE,GrowthCallHandler::TYPE],'Growth module action ownership is incomplete.');
expectGrowthV0350(count($module->actionHandlers())===3,'Growth module must register all three governed execution handlers.');

echo "Growth V0.35 Governed LinkedIn and Call Execution contracts passed.\n";
