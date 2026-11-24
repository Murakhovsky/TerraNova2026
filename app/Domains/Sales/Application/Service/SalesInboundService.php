<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Application\Support\ClientCasePeople;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Domain\Policy\LeadQualificationPolicy;
use Domains\Sales\Domain\Policy\LeadLifecyclePolicy;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\LeadStatus;
use Domains\Sales\Model\PropertyMatchStatus;
use Domains\Sales\Model\SalesActivityType;
use Domains\Sales\Model\SalesCurrency;
use Domains\Sales\Model\SalesPriority;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class SalesInboundService
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
        private ?PipelineRepositoryInterface $pipelines = null,
        private ?ChangeDealStage $changeDealStage = null,
    ) {
    }

    public function attachRequest(int $caseId, int $requestId, ?array $user = null): ClientCaseCommandResult
    {
        return $this->transactions->transactional(function () use ($caseId, $requestId, $user): ClientCaseCommandResult {
            $request = $this->commands->lockInboundRequest($this->organizationId, $requestId);
            if (!$request) return ClientCaseCommandResult::failure('request_not_found');
            $case = $this->readModel->case($caseId);
            if (!$case) return ClientCaseCommandResult::failure('case_not_found');
            $linked = (int) ($request['client_case_id'] ?? 0);
            if ($linked === $caseId) return ClientCaseCommandResult::success('already_attached', ['case_id' => $caseId]);
            if ($linked > 0) return ClientCaseCommandResult::failure('request_already_linked');
            if (!in_array((string)$request['status'],['new','contacted','qualified'],true)) return ClientCaseCommandResult::failure('invalid_lead_transition');
            if (!$this->commands->attachInboundRequest($this->organizationId, $caseId, (int) $case['person_id'], $requestId, $user['id'] ?? null)) throw new RuntimeException('Lead attachment failed after validation.');
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Заявку привʼязано до кейса',
                'body' => 'Вхідна заявка #' . $requestId . ' додана як контекст кейса.', 'due_at' => null, 'completed_at' => null,
            ]);
            $eventId = bin2hex(random_bytes(16));
            $this->events->publish(LeadChanged::create(
                $eventId, $this->organizationId, (string) $requestId,
                ['client_case_id' => ['from' => null, 'to' => $caseId]],
                new EventMetadata($eventId, null, isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system'),
            ));
            return ClientCaseCommandResult::success('attached', ['case_id' => $caseId]);
        });
    }

    public function updateRequest(int $requestId, array $input, ?array $user = null, ?string $correlationId = null): ClientCaseCommandResult
    {
        $correlationId = trim((string) $correlationId) !== '' ? trim((string) $correlationId) : ClientCaseEvents::id();
        return $this->transactions->transactional(function () use ($requestId, $input, $user, $correlationId): ClientCaseCommandResult {
            $request = $this->commands->lockInboundRequest($this->organizationId, $requestId);
            if (!$request) return ClientCaseCommandResult::failure('not_found', ['case_id' => null]);
            $previous=(string)$request['status'];
            $status=(string)($input['status']??$previous);
            if (!in_array($status,LeadStatus::values(),true)) return ClientCaseCommandResult::failure('invalid_status');
            if (array_key_exists('expected_status',$input)) {
                if (array_diff(array_keys($input),['expected_status','status','activity_type','activity_title','activity_body'])!==[] || trim((string)($input['activity_body']??''))!=='') return ClientCaseCommandResult::failure('invalid_qualification_transition');
                $expected=$input['expected_status'];
                if (!is_string($expected) || !LeadLifecyclePolicy::allows($expected,$status)) return ClientCaseCommandResult::failure('invalid_qualification_transition');
                if ($previous===$status) return ClientCaseCommandResult::success('already_applied',['case_id'=>(int)($request['client_case_id']??0)]);
                if ($previous!==$expected) return ClientCaseCommandResult::failure('stale_lead_status');
            }
            $statusChanged=$previous!==$status;
            if ($statusChanged && !LeadLifecyclePolicy::allows($previous,$status)) {
                return ClientCaseCommandResult::failure('invalid_lead_transition');
            }
            $managerId = array_key_exists('assigned_user_id', $input)
                ? $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'])
                : (isset($request['assigned_user_id']) ? (int) $request['assigned_user_id'] : null);
            if (array_key_exists('assigned_user_id',$input) && $managerId===null) return ClientCaseCommandResult::failure('invalid_owner');
            $managerNote = array_key_exists('manager_note',$input)
                ? ClientCaseInput::text((string)$input['manager_note']) : ($request['manager_note']??null);
            $nextContact = array_key_exists('next_contact_at',$input)
                ? ClientCaseInput::dateTime((string)$input['next_contact_at']) : ($request['next_contact_at']??null);
            if (array_key_exists('next_contact_at',$input) && trim((string)$input['next_contact_at'])!=='' && $nextContact===null) return ClientCaseCommandResult::failure('invalid_next_contact_at');
            $contacted = [LeadStatus::Contacted->value, LeadStatus::Qualified->value, LeadStatus::ViewingPlanned->value, LeadStatus::Viewing->value, LeadStatus::Negotiation->value, LeadStatus::Won->value, LeadStatus::Lost->value];
            $lastContacted = $statusChanged && in_array($status, $contacted, true) ? (($request['last_contacted_at'] ?? null) ?: date('Y-m-d H:i:s')) : ($request['last_contacted_at'] ?? null);
            $activityType = ClientCaseInput::allowed((string) ($input['activity_type'] ?? SalesActivityType::StatusChange->value), SalesActivityType::values(), SalesActivityType::StatusChange->value);
            $activityBody = ClientCaseInput::text((string) ($input['activity_body'] ?? '')) ?: $managerNote;
            $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
            $caseId = (int) ($request['client_case_id'] ?? 0);
            $case = $caseId > 0 ? $this->readModel->case($caseId) : null;
            $leadChanges=[];
            foreach (['status'=>$status,'assigned_user_id'=>$managerId,'manager_note'=>$managerNote,'next_contact_at'=>$nextContact,'last_contacted_at'=>$lastContacted] as $field=>$value) {
                $prior=$request[$field]??null;
                if ($field==='assigned_user_id' && $prior!==null) $prior=(int)$prior;
                if ($prior!==$value) $leadChanges[$field]=['from'=>$prior,'to'=>$value];
            }
            if ($leadChanges===[] && trim((string)($input['activity_body']??''))==='' && $completedAt===null) {
                return ClientCaseCommandResult::success('already_applied',['case_id'=>$caseId]);
            }
            if ($caseId>0 && $case===null) throw new RuntimeException('Linked client case could not be reloaded.');
            // Deal outcome is independently governed. Lead WON needs a closed deal;
            // linked LOST must reflect an already lost deal, never close it implicitly.
            if ($statusChanged && $status==='won' && (!$case || ($case['status']??null)!=='closed')) return ClientCaseCommandResult::failure('deal_outcome_required');
            if ($statusChanged && $status==='lost' && $case && ($case['status']??null)!=='lost') return ClientCaseCommandResult::failure('deal_outcome_required');
            if (!$this->commands->updateInboundRequest($this->organizationId, $requestId, [
                'status' => $status, 'assigned_user_id' => $managerId, 'manager_note' => $managerNote,
                'last_contacted_at' => $lastContacted, 'next_contact_at' => $nextContact,
            ])) throw new RuntimeException('Inbound request disappeared during update.');
            $this->commands->addLeadActivity($this->organizationId, $requestId, $user['id'] ?? null, [
                'activity_type' => $activityType, 'title' => ClientCaseInput::limit((string) ($input['activity_title'] ?? 'Заявку оновлено'), 180),
                'body' => $activityBody, 'due_at' => $nextContact, 'completed_at' => $completedAt,
            ]);
            $metadata = ClientCaseEvents::metadata($user, $correlationId);
            if ($leadChanges!==[]) {
                // Do not publish manager-note contents into the event stream.
                $eventChanges=array_intersect_key($leadChanges,['status'=>true,'assigned_user_id'=>true,'next_contact_at'=>true,'last_contacted_at'=>true]);
                if (isset($leadChanges['manager_note'])) $eventChanges['manager_note_changed']=true;
                $this->events->publish(LeadChanged::create(ClientCaseEvents::id(),$this->organizationId,(string)$requestId,$eventChanges,$metadata));
            }
            $canonicalType = match ($status) {
                LeadStatus::Contacted->value => SalesEventType::LEAD_CONTACTED,
                LeadStatus::Qualified->value => SalesEventType::LEAD_QUALIFIED,
                LeadStatus::Disqualified->value => SalesEventType::LEAD_DISQUALIFIED,
                default => null,
            };
            if ($canonicalType !== null && (string) $request['status'] !== $status) {
                $this->events->publish(new DomainEvent(ClientCaseEvents::id(), $this->organizationId, $canonicalType, 'lead', (string) $requestId, ['previous_status' => $request['status'], 'status' => $status], $metadata, new \DateTimeImmutable()));
            }

            // Keep Lead classification independent of Deal ownership, scheduling
            // and terminal outcomes. Only an early forward qualification stage
            // may be mirrored through the existing governed stage use case.
            if ($case && $statusChanged && in_array($status,['contacted','qualified'],true)
                && !ClientCaseStatus::from((string)$case['status'])->isTerminal()) {
                $stage=strtoupper((string)($case['stage']??''));
                $eligible=$status==='contacted'?['NEW']:['NEW','CONTACTED'];
                if (in_array($stage,$eligible,true) && $this->pipelines!==null && $this->changeDealStage!==null) {
                    $target=$this->pipelines->findStageByCode($this->organizationId,(string)$case['pipeline_id'],strtoupper($status));
                    if ($target===null) throw new RuntimeException('Configured qualification stage not found.');
                    $changed=$this->changeDealStage->execute(new ChangeDealStageCommand(
                        $this->organizationId,(string)$caseId,$target->id,
                        isset($user['id'])?'USER':'SYSTEM',isset($user['id'])?(string)$user['id']:'system',$correlationId,
                        expectedStageId:(string)$case['stage_id'],
                    ));
                    if (!$changed->successful) throw new RuntimeException($changed->reason??'Qualification stage synchronization failed.');
                }
            }
            if ($case && $statusChanged) {
                $this->commands->addActivity($this->organizationId,$caseId,(int)$case['person_id'],$user['id']??null,[
                    'activity_type'=>'note','title'=>'Заявку оновлено: '.ClientCaseInput::leadStatusLabel($status),
                    'body'=>null,'due_at'=>null,'completed_at'=>null,
                ]);
            }
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }

    public function createCaseFromRequest(int $requestId, array $input = [], ?array $user = null, ?string $correlationId = null): ClientCaseCommandResult
    {
        $correlationId = trim((string) $correlationId) !== '' ? trim((string) $correlationId) : ClientCaseEvents::id();

        return $this->transactions->transactional(function () use ($requestId, $input, $user, $correlationId): ClientCaseCommandResult {
            // Serialize conversion on the authoritative lead row before any
            // person, case, activity or event is created.
            $request = $this->commands->lockInboundRequest($this->organizationId, $requestId);
            if (!$request) return ClientCaseCommandResult::failure('request_not_found', ['case_id' => null]);
            if (!empty($request['client_case_id'])) {
                $caseId = (int) $request['client_case_id'];
                if (!$this->readModel->case($caseId)) {
                    throw new RuntimeException('Linked client case could not be reloaded.');
                }
                return ClientCaseCommandResult::success('already_attached', ['case_id' => $caseId]);
            }
            if (!in_array((string)$request['status'],['new','contacted','qualified'],true)) return ClientCaseCommandResult::failure('invalid_lead_transition',['case_id'=>null]);
            $name = ClientCaseInput::limit((string) $request['full_name'], 160);
            $phone = ClientCaseInput::nullable((string) ($request['phone'] ?? ''), 50);
            $email = ClientCaseInput::email((string) ($request['email'] ?? ''));
            $managerId = $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'] ?? ($user['id'] ?? null));
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, ['full_name' => $name, 'phone' => $phone, 'email' => $email, 'telegram' => null, 'notes' => null]);
            $caseInput = [
                'full_name' => $name, 'type' => ClientCaseInput::caseTypeFromInbound($request), 'title' => ClientCaseInput::caseTitleFromInbound($request, $name),
                'status' => ClientCaseStatus::Active->value,
                'priority' => ClientCaseInput::allowed((string) ($input['priority'] ?? SalesPriority::Normal->value), SalesPriority::values(), SalesPriority::Normal->value),
                'source' => ClientCaseInput::nullable((string) ($request['source_page'] ?? 'inbound-request'), 120),
                'description' => ClientCaseInput::text((string) ($request['message'] ?? '')), 'currency' => SalesCurrency::Usd->value,
            ];
            $case = ClientCaseInput::caseData($caseInput, $name, $managerId, $this->commands->activePropertyTypeId($request['property_type_id'] ?? null), $this->commands->activeLocationId($request['location_id'] ?? null));
            $caseId = $this->commands->createCase($this->organizationId, $personId, $case);
            if (!$this->commands->attachInboundRequest($this->organizationId, $caseId, $personId, $requestId, $managerId) || !$this->commands->registerInboundRequest($this->organizationId, $caseId, $requestId)) {
                throw new RuntimeException('Inbound request could not be linked to the new client case.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, $personId, $user['id'] ?? null, [
                'activity_type' => SalesActivityType::Note->value, 'title' => 'Кейс створено із заявки',
                'body' => ClientCaseInput::text((string) ($request['message'] ?? '')), 'due_at' => null, 'completed_at' => null,
            ]);
            $propertyId = (int) ($request['property_id'] ?? 0);
            $property = $propertyId > 0 ? $this->commands->property($propertyId) : null;
            if ($property) {
                $this->commands->upsertPropertyMatch($this->organizationId, $caseId, $propertyId, ['match_status' => PropertyMatchStatus::Interested->value, 'score' => null, 'note' => 'Обʼєкт із вхідної заявки']);
                $this->commands->addActivity($this->organizationId, $caseId, $personId, $user['id'] ?? null, [
                    'activity_type' => SalesActivityType::Note->value, 'title' => 'Обʼєкт додано у підбір',
                    'body' => trim($property['public_id'] . ' / ' . $property['title'] . ' / Обʼєкт із вхідної заявки'), 'due_at' => null, 'completed_at' => null,
                ]);
            }
            $created = $this->readModel->case($caseId);
            if (!$created) throw new RuntimeException('Created client case could not be reloaded.');
            $metadata = ClientCaseEvents::metadata($user, $correlationId);
            $this->events->publish(ClientCaseCreated::create(ClientCaseEvents::id(), $this->organizationId, (string) $caseId, [
                'person_id' => $personId,
                'pipeline_id' => $created['pipeline_id'] ?? null,
                'stage_id' => $created['stage_id'] ?? null,
                'stage' => (string) ($created['stage'] ?? ''),
                'source' => 'inbound-request',
            ], $metadata));
            $this->events->publish(LeadChanged::create(ClientCaseEvents::id(), $this->organizationId, (string) $requestId, ['client_case_id' => ['from' => null, 'to' => $caseId], 'status' => ['from' => $request['status'], 'to' => LeadStatus::Qualified->value]], $metadata));
            return ClientCaseCommandResult::success('created', ['case_id' => $caseId, 'person_id' => $personId]);
        });
    }

    public function ensureCase(array $input, ?array $user = null,?string $correlationId=null): ClientCaseCommandResult
    {
        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? $input['name'] ?? ''), 160);
        $phone = ClientCaseInput::nullable((string) ($input['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === null && $email === null)) return ClientCaseCommandResult::failure('contact_required', ['person_id' => null, 'client_case_id' => null]);
        return $this->transactions->transactional(function () use ($input, $user, $name, $phone, $email,$correlationId): ClientCaseCommandResult {
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, [
                'full_name' => $name, 'phone' => $phone, 'email' => $email,
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? ''), 80), 'notes' => null,
            ]);
            $caseInput = [
                'full_name' => $name, 'type' => ClientCaseInput::caseTypeFromInbound($input), 'title' => ClientCaseInput::caseTitleFromInbound($input, $name),
                'status' => ClientCaseStatus::Active->value, 'priority' => SalesPriority::Normal->value,
                'source' => isset($user['id'])?'operator-inbound-request':'site-inbound-request', 'description' => ClientCaseInput::text((string) ($input['message'] ?? $input['comment'] ?? '')), 'currency' => SalesCurrency::Usd->value,
            ];
            $case = ClientCaseInput::caseData($caseInput, $name, null, null, null);
            $caseId = $this->commands->createCase($this->organizationId, $personId, $case);
            $created = $this->readModel->case($caseId);
            if (!$created) throw new RuntimeException('Created client case could not be reloaded.');
            $this->events->publish(ClientCaseCreated::create(bin2hex(random_bytes(16)), $this->organizationId, (string) $caseId, [
                'person_id' => $personId,
                'pipeline_id' => $created['pipeline_id'] ?? null,
                'stage_id' => $created['stage_id'] ?? null,
                'stage' => (string) ($created['stage'] ?? ''),
                'source' => isset($user['id'])?'operator-inbound-request':'site-inbound-request',
            ], ClientCaseEvents::metadata($user,$correlationId)));
            return ClientCaseCommandResult::success('created', ['person_id' => $personId, 'client_case_id' => $caseId]);
        });
    }

    public function registerRequest(int $caseId, int $requestId): void
    {
        $this->transactions->transactional(function () use ($caseId, $requestId): void {
            if (!$this->commands->registerInboundRequest($this->organizationId, $caseId, $requestId)) throw new RuntimeException('Inbound request could not be registered for the client case.');
        });
    }

    public function resolvePropertyId(mixed $value): ?int
    {
        $propertyId = is_numeric($value) ? (int) $value : 0;
        return $this->commands->property($propertyId, true) ? $propertyId : null;
    }
}
