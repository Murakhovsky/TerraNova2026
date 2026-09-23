<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\Contract\GrowthOutboundMessageGatewayInterface;
use Domains\Growth\Application\DTO\GrowthOutboundDelivery;
use Domains\Growth\Automation\Action\GrowthSendMessageHandler;
use Domains\Growth\Automation\Policy\GrowthPolicyCatalog;
use Domains\Growth\Bootstrap\GrowthDomainModule;
use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\GrowthContact;
use Kernel\Action\Action;
use Kernel\Policy\PolicyDecision;

function expectGrowthV0240(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$contacts=new class implements GrowthBuyingCommitteeRepositoryInterface {
    public array $contact=[
        'organization_id'=>'org-1',
        'contact_id'=>'contact-1',
        'full_name'=>'Ada Prospect',
        'identity_type'=>'email',
        'identity_value'=>'ada@example.test',
        'source_references'=>['fixture'],
    ];
    public function createContact(GrowthContact $contact,int $actorId):void{}
    public function findContactByIdentity(string $organizationId,string $identityType,string $identityValue):?array{return null;}
    public function viewContact(string $organizationId,string $contactId):?array{return $contactId==='contact-1'?$this->contact:null;}
    public function linkContactToAccount(string $organizationId,string $accountId,string $contactId,int $actorId):void{}
    public function isContactLinked(string $organizationId,string $accountId,string $contactId):bool{return true;}
    public function createContactSnapshot(ContactSnapshot $snapshot,int $actorId):void{}
    public function viewContactSnapshot(string $organizationId,string $snapshotId):?ContactSnapshot{return null;}
    public function latestContactSnapshotsForAccount(string $organizationId,string $accountId):array{return [];}
    public function accountContacts(string $organizationId,string $accountId):array{return [$this->contact];}
    public function createAssessment(string $assessmentId,string $organizationId,BuyingCommitteeAssessment $assessment,int $actorId):void{}
    public function viewAssessment(string $organizationId,string $assessmentId):?array{return null;}
    public function latestAssessment(string $organizationId,string $accountId):?array{return null;}
};

$outbound=new class implements GrowthOutboundMessageGatewayInterface {
    public array $last=[];
    public string $status='queued';
    public function queueEmail(
        string $organizationId,string $recipientAddress,?string $recipientName,string $body,?string $subject,
        string $locale,string $correlationId,string $idempotencyKey,array $metadata=[]
    ):GrowthOutboundDelivery {
        $this->last=compact(
            'organizationId','recipientAddress','recipientName','body','subject','locale','correlationId','idempotencyKey','metadata'
        );
        return new GrowthOutboundDelivery('GNOT-1','NDLV-1',$this->status,'n8n-outbox-7');
    }
};

$handler=new GrowthSendMessageHandler($contacts,$outbound);
$action=new Action(
    'ACT-1','org-1',GrowthSendMessageHandler::TYPE,'growth_contact','contact-1',
    [
        'channel'=>'email',
        'body'=>'Would a short diagnostic be useful?',
        'growth_candidate_id'=>'candidate-1',
        'growth_recommendation_id'=>'recommendation-1',
    ],
    'GROWTH','recommendation-1','APPROVAL_REQUIRED','MEDIUM','growth-engagement-key',
    new DateTimeImmutable('2026-09-23T18:00:00+00:00'),
    correlationId:'corr-1',
);
$result=$handler->execute($action);
expectGrowthV0240($result->successful,'Growth pre-handoff email handler must queue a valid contact message.');
expectGrowthV0240(($result->data['delivery_status']??null)==='queued','Growth pre-handoff handler must expose queued delivery status.');
expectGrowthV0240(($outbound->last['recipientAddress']??null)==='ada@example.test','Growth handler must resolve contact email at execution time.');
expectGrowthV0240(($outbound->last['idempotencyKey']??null)==='growth-engagement-key','Growth handler must propagate stable external idempotency.');
expectGrowthV0240(($outbound->last['metadata']['growth_candidate_id']??null)==='candidate-1','Growth handler must preserve Candidate provenance.');

$linkedin=new Action(
    'ACT-2','org-1',GrowthSendMessageHandler::TYPE,'growth_contact','contact-1',
    ['channel'=>'linkedin','body'=>'Hello'],
    'GROWTH','recommendation-2','APPROVAL_REQUIRED','MEDIUM','growth-engagement-linkedin',
    new DateTimeImmutable('2026-09-23T18:00:00+00:00'),
);
$linkedinResult=$handler->execute($linkedin);
expectGrowthV0240(!$linkedinResult->successful,'Growth pre-handoff LinkedIn execution must remain unsupported.');

$outbound->status='failed';
$failed=$handler->execute($action);
expectGrowthV0240(!$failed->successful&&$failed->retryable,'Failed durable notification delivery must remain retryable for Kernel execution.');
$outbound->status='queued';

$policies=(new GrowthPolicyCatalog())->policies('org-1');
expectGrowthV0240(count($policies)===1,'Growth V0.24 must define one outbound execution policy.');
expectGrowthV0240($policies[0]->actionType===GrowthSendMessageHandler::TYPE,'Growth policy must govern growth.send_message.');
expectGrowthV0240($policies[0]->decision===PolicyDecision::ApprovalRequired,'Growth outbound message must require approval.');

$module=new GrowthDomainModule($handler);
expectGrowthV0240($module->actionTypes()===[GrowthSendMessageHandler::TYPE],'Growth module must own growth.send_message.');
expectGrowthV0240(count($module->actionHandlers())===1&&$module->actionHandlers()[0]===$handler,'Growth module action handler registration failed.');
expectGrowthV0240($module->bootstrapPolicies()[0]->decision===PolicyDecision::ApprovalRequired,'Growth bootstrap policy must require approval.');

echo "Growth V0.24 Pre-Handoff Engagement Execution contracts passed.\n";
