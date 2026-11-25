<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$transaction = new class implements TransactionManagerInterface {
    private bool $active = false;
    public function transactional(callable $operation): mixed { $this->active = true; try { return $operation(); } finally { $this->active = false; } }
    public function isActive(): bool { return $this->active; }
    public function afterCommit(callable $callback): void { $callback(); }
};
$stored = [];
$store = new class($stored) implements EventStoreInterface {
    public function __construct(private array &$stored) {}
    public function append(DomainEvent $event): void { $this->stored[] = $event; }
    public function find(string $eventId): ?DomainEvent { return null; }
    public function findByAggregate(string $organizationId, string $aggregateType, string $aggregateId, int $limit = 100): array { return []; }
};
$created = [];
$leads = new class($created) implements InboundLeadRepositoryInterface {
    public function __construct(private array &$created) {}
    public function create(string $organizationId, array $lead): int { $this->created[] = [$organizationId, $lead]; return 41+count($this->created); }
    public function existsForUpdate(string $organizationId,int $leadId):bool{return isset($this->created[$leadId-42]) && $this->created[$leadId-42][0]===$organizationId;}
};
$attachments = [];
$cases = new class($attachments) implements InboundCaseResolverInterface {
    public function __construct(private array &$attachments) {}
    public function resolvePropertyId(mixed $value): ?int { return (int) $value > 0 ? (int) $value : null; }
    public function resolvePersonAndCase(array $input,?int $actorId=null,?string $correlationId=null): array { return ['person_id' => 7, 'client_case_id' => 9]; }
    public function attachRequest(int $caseId, int $requestId): void { $this->attachments[] = [$caseId, $requestId]; }
};

$receipts=new class implements SalesMutationReceiptRepositoryInterface {
    private array $items=[];
    public function find(string $organizationId,string $operationType,string $idempotencyKey):?string{return $this->items[$organizationId][$operationType][$idempotencyKey]??null;}
    public function claim(string $organizationId,string $operationType,string $idempotencyKey,string $pendingMutationId):bool{
        if($this->find($organizationId,$operationType,$idempotencyKey)!==null)return false;
        $this->items[$organizationId][$operationType][$idempotencyKey]=$pendingMutationId;return true;
    }
    public function complete(string $organizationId,string $operationType,string $idempotencyKey,string $pendingMutationId,string $mutationId):bool{
        if($this->find($organizationId,$operationType,$idempotencyKey)!==$pendingMutationId)return false;
        $this->items[$organizationId][$operationType][$idempotencyKey]=$mutationId;return true;
    }
};
$useCase = new ReceivePublicLead($leads,$cases,new EventBus($store,$transaction),$transaction,'org-1',$receipts);
$invalid = $useCase->execute(['name' => ''], '/');
if ($invalid->ok || $invalid->code !== 'contact_required' || $created !== []) throw new RuntimeException('Invalid input was persisted.');

$input = [
    'submission_id'=>str_repeat('a',32), 'request_intent'=>'viewing',
    'name' => 'Test Client', 'phone' => '+380000000000', 'property_id' => 12, 'role' => 'покупець', 'deal_type' => 'купівля',
 ];
$result=$useCase->execute($input,'/property/demo');
if (!$result->ok || $result->leadId !== 42) throw new RuntimeException('Valid lead was not accepted.');
if (($created[0][0] ?? null) !== 'org-1' || ($created[0][1]['role'] ?? null) !== 'buyer') throw new RuntimeException('Lead normalization or tenant scope failed.');
if ($attachments !== [[9, 42]]) throw new RuntimeException('Lead was not attached to the client case.');
if (count($stored) !== 1 || $stored[0]->type !== 'sales.lead.created' || $stored[0]->organizationId !== 'org-1') {
    throw new RuntimeException('LeadCreated event was not stored with tenant scope.');
}

$repeat=$useCase->execute(array_reverse($input,true),'/property/demo');
if(!$repeat->ok || $repeat->leadId!==42 || count($created)!==1 || count($stored)!==1 || count($attachments)!==1)throw new RuntimeException('Public lead replay repeated effects.');
if($useCase->execute([...$input,'name'=>'Different'],'/property/demo')->ok)throw new RuntimeException('Public key reused with changed payload.');
if($useCase->execute(array_diff_key($input,['submission_id'=>true]),'/property/demo')->ok)throw new RuntimeException('Missing submission identity accepted.');
if($useCase->execute([...$input,'property_id'=>'12.2'],'/property/demo')->ok)throw new RuntimeException('Malformed property accepted.');
if($useCase->execute([...$input,'submission_id'=>str_repeat('b',32)],'/property/demo')->leadId!==43)throw new RuntimeException('Independent enquiry suppressed.');
if(($created[0][1]['request_intent']??null)!=='viewing')throw new RuntimeException('Intent not persisted.');
echo "Public lead application, submission identity and idempotency PASS.\n";
