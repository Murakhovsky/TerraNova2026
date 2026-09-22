<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthBuyingCommitteeRepositoryInterface;
use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Domain\BuyingCommitteeAssessment;
use Domains\Growth\Domain\ContactSnapshot;
use Domains\Growth\Domain\GrowthContact;
use Domains\Growth\Infrastructure\Handoff\SalesGrowthHandoffTarget;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\OperationResult;

function expectGrowthV090(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$contacts=new class implements GrowthBuyingCommitteeRepositoryInterface {
    /** @var array<string,array<string,mixed>> */
    public array $contacts=[
        'contact-1'=>[
            'organization_id'=>'org-1',
            'contact_id'=>'contact-1',
            'full_name'=>'Ada Buyer',
            'identity_type'=>'email',
            'identity_value'=>'ada@example.test',
            'source_references'=>['source-1'],
        ],
    ];
    public ?array $assessment=[
        'organization_id'=>'org-1',
        'assessment_id'=>'assessment-1',
        'account_id'=>'account-1',
        'champion_contact_ids'=>['contact-1'],
    ];

    public function createContact(GrowthContact $contact,int $actorId): void {}
    public function findContactByIdentity(string $organizationId,string $identityType,string $identityValue): ?array { return null; }
    public function viewContact(string $organizationId,string $contactId): ?array { return $this->contacts[$contactId]??null; }
    public function linkContactToAccount(string $organizationId,string $accountId,string $contactId,int $actorId): void {}
    public function isContactLinked(string $organizationId,string $accountId,string $contactId): bool { return true; }
    public function createContactSnapshot(ContactSnapshot $snapshot,int $actorId): void {}
    public function viewContactSnapshot(string $organizationId,string $snapshotId): ?ContactSnapshot { return null; }
    public function latestContactSnapshotsForAccount(string $organizationId,string $accountId): array { return []; }
    public function accountContacts(string $organizationId,string $accountId): array { return array_values($this->contacts); }
    public function createAssessment(string $assessmentId,string $organizationId,BuyingCommitteeAssessment $assessment,int $actorId): void {}
    public function viewAssessment(string $organizationId,string $assessmentId): ?array { return $this->assessment; }
    public function latestAssessment(string $organizationId,string $accountId): ?array { return $this->assessment; }
};

$salesService=new class implements SalesWriteServiceInterface {
    public array $lastInput=[];
    public int $lastActor=0;
    public string $lastCorrelation='';
    public string $lastIdempotency='';

    public function receivePublicLead(array $input,string $sourcePage): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function createOpportunity(array $input,int $actorId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function updateOpportunity(int $opportunityId,array $input,int $actorId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function attachInboundRequest(int $opportunityId,int $leadId,int $actorId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function updateOpportunityPropertyMatch(int $matchId,array $input,int $actorId): ClientCaseCommandResult { throw new RuntimeException('unused'); }

    public function createLead(array $input,int $actorId,string $correlationId,string $idempotencyKey): ClientCaseCommandResult
    {
        $this->lastInput=$input;
        $this->lastActor=$actorId;
        $this->lastCorrelation=$correlationId;
        $this->lastIdempotency=$idempotencyKey;
        return ClientCaseCommandResult::success('created',['lead_id'=>77]);
    }

    public function updateLead(int $leadId,array $input,int $actorId,string $correlationId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function convertLeadToOpportunity(int $leadId,array $input,int $actorId,string $correlationId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function addOpportunityActivity(int $opportunityId,array $input,int $actorId,string $correlationId): ClientCaseCommandResult { throw new RuntimeException('unused'); }
    public function quickUpdateOpportunity(int $opportunityId,array $input,int $actorId,string $correlationId): ClientCaseCommandResult { throw new RuntimeException('unused'); }

    public function changeOpportunityStage(
        int $opportunityId,string $targetStageId,int $actorId,string $correlationId,
        ?string $lostReasonId=null,?string $lostReasonNote=null,
    ): ChangeDealStageResult { throw new RuntimeException('unused'); }

    public function scheduleNextAction(
        int $opportunityId,string $title,?string $body,DateTimeImmutable $dueAt,
        int $actorId,string $correlationId,string $idempotencyKey,
    ): OperationResult { throw new RuntimeException('unused'); }
};

$factory=new class($salesService) implements SalesWriteServiceFactoryInterface {
    public function __construct(private SalesWriteServiceInterface $service) {}
    public function forOrganization(string $organizationId): SalesWriteServiceInterface { return $this->service; }
};

$handoff=new OpportunityHandoff(
    'candidate-1','org-1','customer_acquisition','acquire','account','account-1','sales',
    ['signal-1'],'Scaling matters','Operational gap','Leadership changed',['signal-1'],[],
    ['fit'=>['score'=>80]],'Implementation value','diagnostic','schedule diagnostic',
);

$target=new SalesGrowthHandoffTarget($contacts,$factory);
$result=$target->accept($handoff,42,'corr-1','stable-target-key');
expectGrowthV090($result->accepted,'Sales Growth handoff should accept a valid champion email.');
expectGrowthV090($result->referenceType==='sales_lead'&&$result->referenceId==='77','Sales Growth handoff returned wrong target reference.');
expectGrowthV090(($salesService->lastInput['full_name']??null)==='Ada Buyer','Sales Growth handoff lost contact name.');
expectGrowthV090(($salesService->lastInput['email']??null)==='ada@example.test','Sales Growth handoff lost contact email.');
expectGrowthV090(($salesService->lastInput['source']??null)==='growth-handoff','Sales Growth handoff provenance source is missing.');
expectGrowthV090($salesService->lastActor===42,'Sales Growth handoff lost actor provenance.');
expectGrowthV090($salesService->lastCorrelation==='corr-1','Sales Growth handoff lost correlation id.');
expectGrowthV090($salesService->lastIdempotency==='stable-target-key','Sales Growth handoff lost target idempotency key.');

$contacts->assessment=null;
$rejected=$target->accept($handoff,42,'corr-2','stable-target-key');
expectGrowthV090(!$rejected->accepted&&str_contains($rejected->reason,'Buying Committee'),'Sales Growth handoff must reject account without Buying Committee.');

$contacts->assessment=[
    'organization_id'=>'org-1',
    'assessment_id'=>'assessment-2',
    'account_id'=>'account-1',
    'champion_contact_ids'=>['contact-1'],
];
$contacts->contacts['contact-1']['identity_type']='linkedin';
$contacts->contacts['contact-1']['identity_value']='https://linkedin.example/ada';
$rejectedIdentity=$target->accept($handoff,42,'corr-3','stable-target-key');
expectGrowthV090(!$rejectedIdentity->accepted&&str_contains($rejectedIdentity->reason,'email identity'),'Sales Growth handoff must reject non-email identity.');

echo "Growth V0.9 Sales Handoff Adapter contracts passed.\n";
