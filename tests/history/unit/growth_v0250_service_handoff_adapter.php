<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\Growth\Application\DTO\OpportunityHandoff;
use Domains\Growth\Infrastructure\Handoff\ServiceGrowthHandoffTarget;
use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleManifest;

function expectGrowthV0250(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$service=new class implements ServiceApplicationBoundary {
    public array $lastInput=[];
    public string $lastOrganization='';
    public int $lastActor=0;
    public string $lastCorrelation='';
    public string $lastIdempotency='';

    public function createRequest(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        $this->lastOrganization=$organizationId;
        $this->lastActor=$actorId;
        $this->lastCorrelation=$correlationId;
        $this->lastIdempotency=$idempotencyKey;
        $this->lastInput=$input;
        return [
            'organization_id'=>$organizationId,
            'request_id'=>'SREQ-TEST-1',
            'case_id'=>'SCASE-TEST-1',
            'summary'=>$input['summary']??null,
            'requester_ref'=>$input['requester_ref']??null,
            'status'=>'open',
        ];
    }

    public function createTicket(string $organizationId,int $actorId,string $correlationId,string $requestId,string $idempotencyKey,array $input):array
    {
        throw new RuntimeException('Growth Service handoff must not create Tickets.');
    }
    public function assignTicket(string $organizationId,int $actorId,string $correlationId,string $ticketId,string $assigneeId,string $idempotencyKey):array{throw new RuntimeException('unused');}
    public function setSla(string $organizationId,int $actorId,string $correlationId,string $ticketId,string $idempotencyKey,array $input):array{throw new RuntimeException('unused');}
    public function escalate(string $organizationId,int $actorId,string $correlationId,string $ticketId,string $reason,string $idempotencyKey):array{throw new RuntimeException('unused');}
    public function resolve(string $organizationId,int $actorId,string $correlationId,string $ticketId,string $summary,string $idempotencyKey):array{throw new RuntimeException('unused');}
    public function close(string $organizationId,int $actorId,string $correlationId,string $ticketId,string $idempotencyKey):array{throw new RuntimeException('unused');}
    public function viewRequest(string $organizationId,string $requestId):?array{return null;}
    public function viewTicket(string $organizationId,string $ticketId):?array{return null;}
};

$states=new class implements ModuleStateRepositoryInterface {
    /** @var array<string,bool> */
    private array $values=[];
    public function enabledOverride(string $organizationId,string $moduleId):?bool
    {
        return $this->values[$organizationId.':'.$moduleId]??null;
    }
    public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void
    {
        $this->values[$organizationId.':'.$moduleId]=$enabled;
    }
};

$modules=new ActiveModuleResolver(new ModuleCatalog([
    new ModuleManifest('service','Service','0.2.0',enabledByDefault:true,schemaVersion:'0.2.0'),
]),$states);

$handoff=new OpportunityHandoff(
    'candidate-1','org-1','customer_expansion','expand','account','account-42','service',
    ['signal-1'],'The customer is expanding into a second operating unit.',
    'Existing process coverage may not support the new unit.',
    'A new operating unit was announced this week.',
    ['signal-1'],['Exact rollout date is unknown'],
    ['fit'=>['score'=>92],'timing'=>['score'=>88]],
    'Expansion project with additional recurring value.',
    'Offer an expansion diagnostic.',
    'Open a structured service intake.',
);

$target=new ServiceGrowthHandoffTarget($service,$modules);
$result=$target->accept($handoff,42,'corr-1','stable-service-target-key');

expectGrowthV0250($result->accepted,'Service Growth handoff must accept a valid package.');
expectGrowthV0250($result->referenceType==='service_request'&&$result->referenceId==='SREQ-TEST-1','Service Growth handoff returned wrong reference.');
expectGrowthV0250($service->lastOrganization==='org-1','Service Growth handoff lost tenant context.');
expectGrowthV0250($service->lastActor===42,'Service Growth handoff lost actor provenance.');
expectGrowthV0250($service->lastCorrelation==='corr-1','Service Growth handoff lost correlation id.');
expectGrowthV0250($service->lastIdempotency==='stable-service-target-key','Service Growth handoff lost target idempotency key.');
expectGrowthV0250(($service->lastInput['requester_ref']??null)==='growth:account:account-42','Service Growth handoff requester provenance is wrong.');
expectGrowthV0250(str_contains((string)($service->lastInput['summary']??''),'Growth candidate: candidate-1'),'Service Growth handoff summary lost Candidate provenance.');
expectGrowthV0250(mb_strlen((string)($service->lastInput['subject']??''))<=220,'Service Growth handoff subject exceeds Service contract.');
expectGrowthV0250(mb_strlen((string)($service->lastInput['summary']??''))<=500,'Service Growth handoff summary exceeds Service contract.');

$states->setEnabled('org-1','service',false);
$disabled=$target->accept($handoff,42,'corr-disabled','stable-service-target-key');
expectGrowthV0250(!$disabled->accepted&&str_contains($disabled->reason,'disabled'),'Service Growth handoff must reject disabled Service module.');

$wrong=new OpportunityHandoff(
    'candidate-2','org-1','customer_expansion','expand','account','account-42','sales',
    ['signal-1'],'Value','Problem','Now',['signal-1'],[],
    ['fit'=>['score'=>80]],'Value','Play','Action',
);
try{
    $target->accept($wrong,42,'corr-wrong','wrong-target-key');
    throw new RuntimeException('Service Growth target must reject package for another Domain.');
}catch(InvalidArgumentException){}

echo "Growth V0.25 Service Handoff Adapter contracts passed.\n";
