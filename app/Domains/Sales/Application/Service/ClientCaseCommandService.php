<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\RecordCompletedCallCommand;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Application\Support\ClientCasePeople;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Model\PropertyMatchStatus;
use Domains\Sales\Model\SalesActivityType;
use Domains\Sales\Model\SalesPriority;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class ClientCaseCommandService
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
        private CompleteSalesCall $completeCall,
        private ?PipelineRepositoryInterface $pipelines = null,
        private ?ChangeDealStage $changeDealStage = null,
        private ?AssignDealOwner $assignDealOwner = null,
    ) {
    }

    public function create(array $input, ?array $user = null): ClientCaseCommandResult
    {
        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? ''), 160);
        $phone = ClientCaseInput::nullable((string) ($input['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === null && $email === null)) {
            return ClientCaseCommandResult::failure('contact_required');
        }

        return $this->transactions->transactional(function () use ($input, $user, $name, $phone, $email): ClientCaseCommandResult {
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, [
                'full_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? ''), 80),
                'notes' => ClientCaseInput::text((string) ($input['person_notes'] ?? '')),
            ]);
            $case = ClientCaseInput::caseData(
                $input,
                $name,
                $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'] ?? ($user['id'] ?? null)),
                $this->commands->activePropertyTypeId($input['property_type_id'] ?? null),
                $this->commands->activeLocationId($input['location_id'] ?? null),
            );
            $caseId = $this->commands->createCase($this->organizationId, $personId, $case);
            $this->commands->addActivity($this->organizationId, $caseId, $personId, $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Кейс створено', 'body' => $case['description'],
                'due_at' => null, 'completed_at' => null,
            ]);
            $created = $this->readModel->case($caseId);
            if (!$created) throw new RuntimeException('Created client case could not be reloaded.');
            $this->events->publish(ClientCaseCreated::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                [
                    'person_id' => $personId,
                    'pipeline_id' => $created['pipeline_id'] ?? null,
                    'stage_id' => $created['stage_id'] ?? null,
                    'stage' => (string) ($created['stage'] ?? ''),
                ],
                ClientCaseEvents::metadata($user),
            ));
            return ClientCaseCommandResult::success('created', ['case_id' => $caseId, 'person_id' => $personId]);
        });
    }

    public function update(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $existing = $this->readModel->case($caseId);
        if (!$existing) return ClientCaseCommandResult::failure('not_found');

        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? $existing['full_name'] ?? ''), 160);
        $managerId = $this->commands->activeManagerId(
            $this->organizationId,
            $input['assigned_user_id'] ?? ($existing['assigned_user_id'] ?? null),
        );
        $case = ClientCaseInput::caseData(
            $input, $name, $managerId,
            $this->commands->activePropertyTypeId($input['property_type_id'] ?? ($existing['property_type_id'] ?? null)),
            $this->commands->activeLocationId($input['location_id'] ?? ($existing['location_id'] ?? null)),
            $existing,
        );
        $correlationId = ClientCaseEvents::id();
        $targetStageId = isset($input['stage_id']) ? trim((string) $input['stage_id']) : null;
        if ($targetStageId === null && array_key_exists('stage', $input)) {
            if ($this->pipelines === null) return ClientCaseCommandResult::failure('stage_service_unavailable');
            $stage = $this->pipelines->findStageByCode(
                $this->organizationId,
                (string) ($existing['pipeline_id'] ?? ''),
                $this->canonicalStageCode((string) $input['stage']),
            );
            if ($stage === null) return ClientCaseCommandResult::failure('invalid_stage');
            $targetStageId = $stage->id;
        }

        return $this->transactions->transactional(function () use ($caseId, $input, $user, $existing, $name, $case, $correlationId, $targetStageId): ClientCaseCommandResult {
            if (!$this->commands->updatePerson($this->organizationId, (int) $existing['person_id'], [
                'full_name' => $name,
                'phone' => ClientCaseInput::nullable((string) ($input['phone'] ?? $existing['phone'] ?? ''), 50),
                'email' => ClientCaseInput::email((string) ($input['email'] ?? $existing['email'] ?? '')),
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? $existing['telegram'] ?? ''), 80),
                'notes' => ClientCaseInput::text((string) ($input['person_notes'] ?? $existing['person_notes'] ?? '')),
            ])) throw new RuntimeException('Client person disappeared during update.');
            if (!$this->commands->updateCase($this->organizationId, $caseId, $case)) {
                throw new RuntimeException('Client case disappeared during update.');
            }
            if ($targetStageId !== null && $targetStageId !== (string) ($existing['stage_id'] ?? '')) {
                if ($this->changeDealStage === null) throw new RuntimeException('Stage service is unavailable.');
                $stageResult = $this->changeDealStage->execute(new ChangeDealStageCommand(
                    $this->organizationId, (string) $caseId, $targetStageId,
                    isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system', $correlationId,
                ));
                if (!$stageResult->successful) throw new RuntimeException($stageResult->reason ?? 'Invalid stage transition.');
            }
            if ($case['assigned_user_id'] !== null && (int) $case['assigned_user_id'] !== (int) ($existing['assigned_user_id'] ?? 0)) {
                if ($this->assignDealOwner === null) throw new RuntimeException('Owner assignment service is unavailable.');
                $assignment = $this->assignDealOwner->execute(
                    $this->organizationId, (string) $caseId, (int) $case['assigned_user_id'], $correlationId,
                    isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system',
                );
                if (!$assignment->successful) throw new RuntimeException($assignment->error ?? 'Owner assignment failed.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $existing['person_id'], $user['id'] ?? null, [
                'activity_type' => 'status_change', 'title' => 'Кейс оновлено',
                'body' => 'Оновлено дані людини або параметри кейсу.', 'due_at' => null, 'completed_at' => null,
            ]);
            $this->events->publish(ClientCaseChanged::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                ['details' => ['from' => 'existing', 'to' => 'updated']], ClientCaseEvents::metadata($user, $correlationId),
            ));
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }

    public function quickUpdate(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('not_found');

        $targetStageId = isset($input['stage_id']) ? trim((string) $input['stage_id']) : null;
        if ($targetStageId === null && array_key_exists('stage', $input)) {
            if ($this->pipelines === null) return ClientCaseCommandResult::failure('stage_service_unavailable');
            $resolved = $this->pipelines->findStageByCode(
                $this->organizationId, (string) ($case['pipeline_id'] ?? ''), $this->canonicalStageCode((string) $input['stage']),
            );
            if ($resolved === null) return ClientCaseCommandResult::failure('invalid_stage');
            $targetStageId = $resolved->id;
        }
        $priority = $this->allowed((string) ($input['priority'] ?? $case['priority']), SalesPriority::values(), (string) $case['priority']);
        $managerId = array_key_exists('assigned_user_id', $input)
            ? $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'])
            : ($case['assigned_user_id'] ?? null);
        $nextContact = array_key_exists('next_contact_at', $input)
            ? $this->dateTime((string) ($input['next_contact_at'] ?? '')) : ($case['next_contact_at'] ?? null);
        $correlationId = bin2hex(random_bytes(16));
        $simpleChanges = [];
        if ((string) ($case['priority'] ?? '') !== $priority) {
            $simpleChanges['priority'] = ['from' => (string) ($case['priority'] ?? ''), 'to' => $priority];
        }
        if (($case['next_contact_at'] ?? null) !== $nextContact) {
            $simpleChanges['next_contact_at'] = ['from' => $case['next_contact_at'] ?? null, 'to' => $nextContact];
        }

        $ok = $this->transactions->transactional(function () use ($case, $caseId, $targetStageId, $priority, $managerId, $nextContact, $user, $correlationId, $simpleChanges): bool {
            if ($targetStageId !== null && $targetStageId !== (string) ($case['stage_id'] ?? '')) {
                if ($this->changeDealStage === null) return false;
                $stageResult = $this->changeDealStage->execute(new ChangeDealStageCommand(
                    $this->organizationId, (string) $caseId, $targetStageId,
                    isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system', $correlationId,
                ));
                if (!$stageResult->successful) return false;
            }
            if ($managerId !== null && (int) $managerId !== (int) ($case['assigned_user_id'] ?? 0)) {
                if ($this->assignDealOwner === null) return false;
                $assignment = $this->assignDealOwner->execute(
                    $this->organizationId, (string) $caseId, (int) $managerId, $correlationId,
                    isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system',
                );
                if (!$assignment->successful) return false;
            }
            if (!$this->commands->quickUpdate($this->organizationId, $caseId, [
                'priority' => $priority, 'next_contact_at' => $nextContact,
            ])) return false;
            if ($simpleChanges !== []) {
                $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                    'activity_type' => 'status_change', 'title' => 'Кейс швидко оновлено',
                    'body' => 'Оновлено пріоритет або наступний контакт.', 'due_at' => null, 'completed_at' => null,
                ]);
                $this->events->publish(ClientCaseChanged::create(
                    bin2hex(random_bytes(16)), $this->organizationId, (string) $caseId,
                    $simpleChanges,
                    new EventMetadata($correlationId, null, isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system'),
                ));
            }
            return true;
        });
        return $ok ? ClientCaseCommandResult::success('updated') : ClientCaseCommandResult::failure('not_found');
    }

    public function addActivity(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('not_found');
        $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
        $type = $this->allowed((string) ($input['activity_type'] ?? 'note'), SalesActivityType::values(), 'note');
        if ($type === 'call' && $completedAt !== null) {
            $eventId = bin2hex(random_bytes(16));
            $result = trim(mb_substr((string) ($input['call_result'] ?? $input['result'] ?? ''), 0, 100));
            $this->completeCall->execute(new RecordCompletedCallCommand(
                $this->organizationId, (string) $caseId, (string) $case['person_id'], isset($user['id']) ? (string) $user['id'] : null,
                mb_substr(trim((string) ($input['title'] ?? 'Дзвінок')), 0, 180), $this->nullable((string) ($input['body'] ?? '')),
                max(0, (int) ($input['duration_seconds'] ?? $input['duration'] ?? 0)), $result !== '' ? $result : 'completed',
                $eventId, $eventId, isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system',
            ));
            return ClientCaseCommandResult::success('activity_added');
        }
        $this->transactions->transactional(function () use ($case, $caseId, $input, $user, $completedAt, $type): void {
            $activityId = $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => $type, 'title' => mb_substr(trim((string) ($input['title'] ?? 'Нотатка')), 0, 180),
                'body' => $this->nullable((string) ($input['body'] ?? '')), 'due_at' => $this->dateTime((string) ($input['due_at'] ?? '')),
                'completed_at' => $completedAt,
            ]);
            if ($completedAt) $this->commands->clearNextContact($this->organizationId, $caseId);
            $eventType = match (true) {
                $type === 'meeting' && $completedAt !== null => SalesEventType::MEETING_COMPLETED,
                $type === 'task' && $completedAt !== null => SalesEventType::TASK_COMPLETED,
                $type === 'task' => SalesEventType::TASK_CREATED,
                $type === 'followup' && $completedAt !== null => SalesEventType::FOLLOWUP_COMPLETED,
                $type === 'followup' => SalesEventType::FOLLOWUP_CREATED,
                default => null,
            };
            if ($eventType !== null) {
                $eventId = bin2hex(random_bytes(16));
                $this->events->publish(new DomainEvent(
                    $eventId, $this->organizationId, $eventType, 'deal', (string) $caseId,
                    ['activity_id' => (string) $activityId, 'person_id' => (string) $case['person_id']],
                    new EventMetadata($eventId, null, isset($user['id']) ? 'USER' : 'SYSTEM', isset($user['id']) ? (string) $user['id'] : 'system'),
                    new \DateTimeImmutable(),
                ));
            }
        });
        return ClientCaseCommandResult::success('activity_added');
    }

    public function addPropertyMatch(int $caseId, int $propertyId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $case = $this->readModel->case($caseId);
        if (!$case) return ClientCaseCommandResult::failure('case_not_found');
        $property = $this->commands->property($propertyId);
        if (!$property) return ClientCaseCommandResult::failure('property_not_found');
        $match = [
            'match_status' => ClientCaseInput::allowed((string) ($input['match_status'] ?? 'suggested'), PropertyMatchStatus::values(), 'suggested'),
            'score' => ClientCaseInput::score($input['score'] ?? null),
            'note' => ClientCaseInput::nullable((string) ($input['note'] ?? ''), 500),
        ];
        return $this->transactions->transactional(function () use ($case, $caseId, $propertyId, $property, $match, $user): ClientCaseCommandResult {
            if (!$this->commands->upsertPropertyMatch($this->organizationId, $caseId, $propertyId, $match)) {
                throw new RuntimeException('Client case disappeared while adding a property match.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Обʼєкт додано у підбір',
                'body' => trim($property['public_id'] . ' / ' . $property['title'] . ($match['note'] ? ' / ' . $match['note'] : '')),
                'due_at' => null, 'completed_at' => null,
            ]);
            return ClientCaseCommandResult::success('added', ['case_id' => $caseId]);
        });
    }

    public function updatePropertyMatch(int $matchId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $existing = $this->commands->propertyMatch($this->organizationId, $matchId);
        if (!$existing) return ClientCaseCommandResult::failure('not_found');
        $case = $this->readModel->case((int) $existing['client_case_id']);
        if (!$case) return ClientCaseCommandResult::failure('not_found');
        $match = [
            'match_status' => ClientCaseInput::allowed((string) ($input['match_status'] ?? 'suggested'), PropertyMatchStatus::values(), 'suggested'),
            'score' => ClientCaseInput::score($input['score'] ?? null),
            'note' => ClientCaseInput::nullable((string) ($input['note'] ?? ''), 500),
        ];
        $caseId = (int) $existing['client_case_id'];
        return $this->transactions->transactional(function () use ($matchId, $match, $existing, $case, $caseId, $user): ClientCaseCommandResult {
            if (!$this->commands->updatePropertyMatch($this->organizationId, $matchId, $match)) {
                throw new RuntimeException('Property match disappeared during update.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Підбір обʼєкта оновлено',
                'body' => trim(($existing['public_id'] ?? '') . ' / ' . ($existing['title'] ?? '') . ' / ' . $match['match_status']),
                'due_at' => null, 'completed_at' => null,
            ]);
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }

    private function canonicalStageCode(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'new' => 'NEW', 'contacted' => 'CONTACTED', 'qualification', 'need_defined', 'qualified' => 'QUALIFIED', 'matching', 'proposal' => 'PROPOSAL',
            'viewing', 'meeting' => 'MEETING', 'negotiation' => 'NEGOTIATION', 'deal', 'aftercare', 'won' => 'WON',
            'lost' => 'LOST', default => strtoupper($legacy),
        };
    }

    private function allowed(string $value, array $allowed, string $default): string
    { return in_array($value, $allowed, true) ? $value : $default; }

    private function nullable(string $value): ?string
    { $value = trim($value); return $value === '' ? null : mb_substr($value, 0, 4000); }

    private function dateTime(string $value): ?string
    { $timestamp = strtotime(trim($value)); return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null; }
}
