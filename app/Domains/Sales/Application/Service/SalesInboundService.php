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
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('case_not_found');
        $ok = $this->transactions->transactional(function () use ($case, $caseId, $requestId, $user): bool {
            if (!$this->commands->attachInboundRequest($this->organizationId, $caseId, (int) $case['person_id'], $requestId, $user['id'] ?? null)) return false;
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
            return true;
        });
        return $ok ? ClientCaseCommandResult::success('attached') : ClientCaseCommandResult::failure('request_not_found');
    }

    public function updateRequest(int $requestId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $request = $this->commands->inboundRequest($this->organizationId, $requestId);
        if (!$request) return ClientCaseCommandResult::failure('not_found', ['case_id' => null]);
        $status = ClientCaseInput::allowed((string) ($input['status'] ?? $request['status']), LeadStatus::values(), (string) $request['status']);
        $managerId = array_key_exists('assigned_user_id', $input)
            ? $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'])
            : (isset($request['assigned_user_id']) ? (int) $request['assigned_user_id'] : null);
        $managerNote = ClientCaseInput::text((string) ($input['manager_note'] ?? ($request['manager_note'] ?? '')));
        $nextContact = ClientCaseInput::dateTime((string) ($input['next_contact_at'] ?? ''));
        $contacted = [LeadStatus::Contacted->value, LeadStatus::Qualified->value, LeadStatus::ViewingPlanned->value, LeadStatus::Viewing->value, LeadStatus::Negotiation->value, LeadStatus::Won->value, LeadStatus::Lost->value];
        $lastContacted = in_array($status, $contacted, true) ? (($request['last_contacted_at'] ?? null) ?: date('Y-m-d H:i:s')) : ($request['last_contacted_at'] ?? null);
        $activityType = ClientCaseInput::allowed((string) ($input['activity_type'] ?? SalesActivityType::StatusChange->value), SalesActivityType::values(), SalesActivityType::StatusChange->value);
        $activityBody = ClientCaseInput::text((string) ($input['activity_body'] ?? '')) ?: $managerNote;
        $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
        $caseId = (int) ($request['client_case_id'] ?? 0);
        $case = $caseId > 0 ? $this->readModel->case($caseId) : null;
        $correlationId = ClientCaseEvents::id();

        return $this->transactions->transactional(function () use ($requestId, $input, $user, $request, $status, $managerId, $managerNote, $nextContact, $lastContacted, $activityType, $activityBody, $completedAt, $caseId, $case, $correlationId): ClientCaseCommandResult {
            if (!$this->commands->updateInboundRequest($this->organizationId, $requestId, [
                'status' => $status, 'assigned_user_id' => $managerId, 'manager_note' => $managerNote,
                'last_contacted_at' => $lastContacted, 'next_contact_at' => $nextContact,
            ])) throw new RuntimeException('Inbound request disappeared during update.');
            $this->commands->addLeadActivity($this->organizationId, $requestId, $user['id'] ?? null, [
                'activity_type' => $activityType, 'title' => ClientCaseInput::limit((string) ($input['activity_title'] ?? 'Заявку оновлено'), 180),
                'body' => $activityBody, 'due_at' => $nextContact, 'completed_at' => $completedAt,
            ]);
            $metadata = ClientCaseEvents::metadata($user, $correlationId);
            $this->events->publish(LeadChanged::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $requestId,
                ['status' => ['from' => $request['status'], 'to' => $status], 'assigned_user_id' => ['from' => $request['assigned_user_id'] ?? null, 'to' => $managerId]], $metadata,
            ));
            $canonicalType = match ($status) {
                LeadStatus::Contacted->value => SalesEventType::LEAD_CONTACTED,
                LeadStatus::Qualified->value => SalesEventType::LEAD_QUALIFIED,
                LeadStatus::Lost->value => SalesEventType::LEAD_DISQUALIFIED,
                default => null,
            };
            if ($canonicalType !== null && (string) $request['status'] !== $status) {
                $this->events->publish(new DomainEvent(ClientCaseEvents::id(), $this->organizationId, $canonicalType, 'lead', (string) $requestId, ['previous_status' => $request['status'], 'status' => $status], $metadata, new \DateTimeImmutable()));
            }
            if ($case) {
                $state = ClientCaseInput::caseStateForLead($status);
                if (!$this->commands->syncCaseFromLead($this->organizationId, $caseId, [
                    'status' => $state['status'], 'assigned_user_id' => $managerId, 'next_contact_at' => $nextContact,
                    'closed_at' => ClientCaseStatus::from($state['status'])->isTerminal() ? date('Y-m-d H:i:s') : null,
                ])) throw new RuntimeException('Linked client case disappeared during lead synchronization.');

                if ($this->pipelines !== null && $this->changeDealStage !== null) {
                    $target = $this->pipelines->findStageByCode($this->organizationId, (string) ($case['pipeline_id'] ?? ''), $state['stage']);
                    if ($target === null) throw new RuntimeException('Configured stage for lead status was not found.');
                    if ($target->id !== (string) ($case['stage_id'] ?? '')) {
                        $changed = $this->changeDealStage->execute(new ChangeDealStageCommand(
                            $this->organizationId, (string) $caseId, $target->id,
                            isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system', $correlationId,
                        ));
                        if (!$changed->successful) throw new RuntimeException($changed->reason ?? 'Lead stage synchronization failed.');
                    }
                }

                $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                    'activity_type' => $activityType === SalesActivityType::Viewing->value ? SalesActivityType::Viewing->value : SalesActivityType::Note->value,
                    'title' => 'Заявку оновлено: ' . ClientCaseInput::leadStatusLabel($status), 'body' => $activityBody,
                    'due_at' => $nextContact, 'completed_at' => $completedAt,
                ]);
                if ($completedAt !== null) $this->commands->clearNextContact($this->organizationId, $caseId);

                $changes = [];
                if ((string) $case['status'] !== $state['status']) {
                    $changes['status'] = ['from' => (string) $case['status'], 'to' => $state['status']];
                }
                if ((int) ($case['assigned_user_id'] ?? 0) !== (int) ($managerId ?? 0)) {
                    $changes['assigned_user_id'] = ['from' => $case['assigned_user_id'] ?? null, 'to' => $managerId];
                }
                if (($case['next_contact_at'] ?? null) !== $nextContact) {
                    $changes['next_contact_at'] = ['from' => $case['next_contact_at'] ?? null, 'to' => $nextContact];
                }
                if ($changes !== []) {
                    $this->events->publish(ClientCaseChanged::create(
                        ClientCaseEvents::id(), $this->organizationId, (string) $caseId, $changes, $metadata,
                    ));
                }
            }
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }

    public function createCaseFromRequest(int $requestId, array $input = [], ?array $user = null): ClientCaseCommandResult
    {
        $request = $this->commands->inboundRequest($this->organizationId, $requestId);
        if (!$request) return ClientCaseCommandResult::failure('request_not_found', ['case_id' => null]);
        if (!empty($request['client_case_id'])) return ClientCaseCommandResult::success('already_attached', ['case_id' => (int) $request['client_case_id']]);
        $name = ClientCaseInput::limit((string) $request['full_name'], 160);
        $phone = ClientCaseInput::nullable((string) ($request['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($request['email'] ?? ''));
        $managerId = $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'] ?? ($user['id'] ?? null));

        return $this->transactions->transactional(function () use ($requestId, $request, $input, $user, $name, $phone, $email, $managerId): ClientCaseCommandResult {
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
            $metadata = ClientCaseEvents::metadata($user);
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

    public function ensureCase(array $input, ?array $user = null): ClientCaseCommandResult
    {
        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? $input['name'] ?? ''), 160);
        $phone = ClientCaseInput::nullable((string) ($input['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === null && $email === null)) return ClientCaseCommandResult::failure('contact_required', ['person_id' => null, 'client_case_id' => null]);
        return $this->transactions->transactional(function () use ($input, $user, $name, $phone, $email): ClientCaseCommandResult {
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, [
                'full_name' => $name, 'phone' => $phone, 'email' => $email,
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? ''), 80), 'notes' => null,
            ]);
            $caseInput = [
                'full_name' => $name, 'type' => ClientCaseInput::caseTypeFromInbound($input), 'title' => ClientCaseInput::caseTitleFromInbound($input, $name),
                'status' => ClientCaseStatus::Active->value, 'priority' => SalesPriority::Normal->value,
                'source' => 'site-inbound-request', 'description' => ClientCaseInput::text((string) ($input['message'] ?? $input['comment'] ?? '')), 'currency' => SalesCurrency::Usd->value,
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
                'source' => 'site-inbound-request',
            ], ClientCaseEvents::metadata($user)));
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
