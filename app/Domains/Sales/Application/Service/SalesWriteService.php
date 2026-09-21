<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\ScheduleFollowupCommand;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Domains\Sales\Application\UseCase\ScheduleDealFollowup;
use Domains\Sales\Automation\Event\LeadCreated;
use Domains\Sales\Model\LeadStatus;
use Domains\Sales\Model\SalesActivityType;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class SalesWriteService implements SalesWriteServiceInterface
{
    private const CREATE_LEAD_OPERATION = 'api.lead.create';

    public function __construct(
        private string $organizationId,
        private InboundLeadRepositoryInterface $leads,
        private ClientCaseCommandRepositoryInterface $commands,
        private SalesInboundService $inbound,
        private ClientCaseCommandService $cases,
        private ReceivePublicLead $publicLeads,
        private ChangeDealStage $stages,
        private ScheduleDealFollowup $followups,
        private SalesMutationReceiptRepositoryInterface $receipts,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function receivePublicLead(array $input,string $sourcePage): ClientCaseCommandResult
    {
        $result=$this->publicLeads->execute($input,$sourcePage);
        return $result->ok
            ? ClientCaseCommandResult::success($result->code,['lead_id'=>$result->leadId])
            : ClientCaseCommandResult::failure($result->code);
    }
    public function createOpportunity(array $input,int $actorId): ClientCaseCommandResult { return $this->cases->create($input,['id'=>$actorId]); }
    public function updateOpportunity(int $opportunityId,array $input,int $actorId): ClientCaseCommandResult { return $this->cases->update($opportunityId,$input,['id'=>$actorId]); }
    public function attachInboundRequest(int $opportunityId,int $leadId,int $actorId): ClientCaseCommandResult { return $this->inbound->attachRequest($opportunityId,$leadId,['id'=>$actorId]); }
    public function updateOpportunityPropertyMatch(int $matchId,array $input,int $actorId): ClientCaseCommandResult { return $this->cases->updatePropertyMatch($matchId,$input,['id'=>$actorId]); }

    public function createLead(array $input, int $actorId, string $correlationId, string $idempotencyKey): ClientCaseCommandResult
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            return ClientCaseCommandResult::failure('idempotency_key_required');
        }

        $existingMutation = $this->receipts->find($this->organizationId, self::CREATE_LEAD_OPERATION, $idempotencyKey);
        if ($existingMutation !== null) {
            return ctype_digit($existingMutation)
                ? ClientCaseCommandResult::success('duplicate', ['lead_id' => (int) $existingMutation])
                : ClientCaseCommandResult::failure('idempotency_conflict');
        }

        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === '' && $email === '')) {
            return ClientCaseCommandResult::failure('contact_required');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ClientCaseCommandResult::failure('invalid_email');
        }

        $rawNextContact = trim((string) ($input['next_contact_at'] ?? ''));
        if ($rawNextContact !== '' && strtotime($rawNextContact) === false) {
            return ClientCaseCommandResult::failure('invalid_next_contact_at');
        }

        $requestedOwner = (int) ($input['owner_id'] ?? $input['assigned_user_id'] ?? $actorId);
        $ownerId = $this->commands->activeManagerId($this->organizationId, $requestedOwner);
        if ($requestedOwner > 0 && $ownerId === null) {
            return ClientCaseCommandResult::failure('invalid_owner');
        }

        $pendingMutationId = 'pending:' . bin2hex(random_bytes(16));

        return $this->transactions->transactional(function () use (
            $input,
            $actorId,
            $correlationId,
            $idempotencyKey,
            $name,
            $phone,
            $email,
            $ownerId,
            $pendingMutationId,
        ): ClientCaseCommandResult {
            if (!$this->receipts->claim(
                $this->organizationId,
                self::CREATE_LEAD_OPERATION,
                $idempotencyKey,
                $pendingMutationId,
            )) {
                $existing = $this->receipts->find($this->organizationId, self::CREATE_LEAD_OPERATION, $idempotencyKey);
                return $existing !== null && ctype_digit($existing)
                    ? ClientCaseCommandResult::success('duplicate', ['lead_id' => (int) $existing])
                    : ClientCaseCommandResult::failure('idempotency_conflict');
            }

            $leadId = $this->leads->create($this->organizationId, [
                'person_id' => null,
                'client_case_id' => null,
                'property_id' => $this->positiveInt($input['property_id'] ?? null),
                'full_name' => mb_substr($name, 0, 160),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'email' => $email !== '' ? mb_substr($email, 0, 160) : null,
                'role' => $this->role((string) ($input['role'] ?? 'buyer')),
                'deal_type' => $this->dealType((string) ($input['deal_type'] ?? 'consultation')),
                'message' => ($message = trim((string) ($input['message'] ?? ''))) !== '' ? mb_substr($message, 0, 4000) : null,
                'source_page' => mb_substr(trim((string) ($input['source'] ?? 'manager-api')), 0, 255),
                'request_intent' => $this->requestIntent((string) ($input['request_intent'] ?? 'general_contact')),
                'manager_note' => ($note = trim((string) ($input['manager_note'] ?? ''))) !== '' ? mb_substr($note, 0, 4000) : null,
                'assigned_user_id' => $ownerId,
                'next_contact_at' => $this->dateTimeOrNull($input['next_contact_at'] ?? null),
            ]);

            if (!$this->receipts->complete(
                $this->organizationId,
                self::CREATE_LEAD_OPERATION,
                $idempotencyKey,
                $pendingMutationId,
                (string) $leadId,
            )) {
                throw new RuntimeException('Lead mutation receipt could not be completed.');
            }

            $eventId = bin2hex(random_bytes(16));
            $this->events->publish(LeadCreated::create(
                $eventId,
                $this->organizationId,
                (string) $leadId,
                [
                    'client_case_id' => null,
                    'source_page' => (string) ($input['source'] ?? 'manager-api'),
                    'status' => LeadStatus::New->value,
                    'assigned_user_id' => $ownerId,
                ],
                new EventMetadata($correlationId !== '' ? $correlationId : $eventId, null, 'USER', (string) $actorId),
            ));

            return ClientCaseCommandResult::success('created', ['lead_id' => $leadId]);
        });
    }

    public function updateLead(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        if ($leadId <= 0) return ClientCaseCommandResult::failure('not_found');

        $changes = [];
        if (array_key_exists('status', $input)) {
            $status = strtolower(trim((string) $input['status']));
            if (!in_array($status, LeadStatus::values(), true)) {
                return ClientCaseCommandResult::failure('invalid_status');
            }
            $changes['status'] = $status;
        }
        if (array_key_exists('owner_id', $input) || array_key_exists('assigned_user_id', $input)) {
            $requestedOwner = (int) ($input['owner_id'] ?? $input['assigned_user_id'] ?? 0);
            if ($requestedOwner <= 0 || $this->commands->activeManagerId($this->organizationId, $requestedOwner) === null) {
                return ClientCaseCommandResult::failure('invalid_owner');
            }
            $changes['assigned_user_id'] = $requestedOwner;
        }
        if (array_key_exists('manager_note', $input)) {
            $changes['manager_note'] = mb_substr(trim((string) $input['manager_note']), 0, 4000);
        }
        if (array_key_exists('next_contact_at', $input)) {
            $raw = trim((string) ($input['next_contact_at'] ?? ''));
            if ($raw !== '' && strtotime($raw) === false) {
                return ClientCaseCommandResult::failure('invalid_next_contact_at');
            }
            $changes['next_contact_at'] = $raw;
        }
        if ($changes === []) return ClientCaseCommandResult::failure('no_changes');

        $changes['activity_type'] = SalesActivityType::StatusChange->value;
        $changes['activity_title'] = 'Lead updated from Symfony API';
        $changes['activity_body'] = mb_substr(trim((string) ($input['note'] ?? '')), 0, 4000);

        return $this->inbound->updateRequest($leadId, $changes, ['id' => $actorId], $correlationId);
    }

    public function convertLeadToOpportunity(int $leadId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $requestedOwner = (int) ($input['owner_id'] ?? $actorId);
        if ($requestedOwner <= 0 || $this->commands->activeManagerId($this->organizationId, $requestedOwner) === null) {
            return ClientCaseCommandResult::failure('invalid_owner');
        }

        return $this->inbound->createCaseFromRequest($leadId, [
            'assigned_user_id' => $requestedOwner,
            'priority' => strtolower(trim((string) ($input['priority'] ?? 'normal'))),
        ], ['id' => $actorId], $correlationId);
    }

    public function addOpportunityActivity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        $type = strtolower(trim((string) ($input['activity_type'] ?? 'note')));
        if (!in_array($type, SalesActivityType::values(), true)) {
            return ClientCaseCommandResult::failure('invalid_activity_type');
        }
        if (trim((string) ($input['title'] ?? '')) === '') {
            return ClientCaseCommandResult::failure('activity_title_required');
        }

        return $this->cases->addActivity($opportunityId, [
            'activity_type' => $type,
            'title' => mb_substr(trim((string) $input['title']), 0, 180),
            'body' => mb_substr(trim((string) ($input['body'] ?? '')), 0, 4000),
            'due_at' => trim((string) ($input['due_at'] ?? '')),
            'completed' => !empty($input['completed']),
            'call_result' => mb_substr(trim((string) ($input['call_result'] ?? '')), 0, 100),
            'duration_seconds' => max(0, (int) ($input['duration_seconds'] ?? 0)),
        ], ['id' => $actorId], $correlationId);
    }

    public function quickUpdateOpportunity(int $opportunityId, array $input, int $actorId, string $correlationId): ClientCaseCommandResult
    {
        if ($opportunityId <= 0) {
            return ClientCaseCommandResult::failure('not_found');
        }

        return $this->cases->quickUpdate(
            $opportunityId,
            $input,
            ['id' => $actorId],
            $correlationId,
        );
    }

    public function changeOpportunityStage(
        int $opportunityId,
        string $targetStageId,
        int $actorId,
        string $correlationId,
        ?string $lostReasonId = null,
        ?string $lostReasonNote = null,
    ): ChangeDealStageResult {
        return $this->stages->execute(new ChangeDealStageCommand(
            $this->organizationId,
            (string) $opportunityId,
            trim($targetStageId),
            'USER',
            (string) $actorId,
            $correlationId,
            $lostReasonId,
            $lostReasonNote,
        ));
    }

    public function scheduleNextAction(
        int $opportunityId,
        string $title,
        ?string $body,
        DateTimeImmutable $dueAt,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
    ): OperationResult {
        if ($dueAt <= new DateTimeImmutable()) {
            return OperationResult::failure('next_action_must_be_future');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            return OperationResult::failure('idempotency_key_required');
        }

        return $this->followups->execute(
            new ScheduleFollowupCommand(
                $this->organizationId,
                (string) $opportunityId,
                mb_substr(trim($title) !== '' ? trim($title) : 'Follow-up', 0, 180),
                $body !== null && trim($body) !== '' ? mb_substr(trim($body), 0, 4000) : null,
                $dueAt,
                $idempotencyKey,
            ),
            $correlationId,
            'USER',
            (string) $actorId,
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') return null;
        $timestamp = strtotime($raw);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function role(string $value): string
    {
        return in_array($value = strtolower(trim($value)), ['buyer','seller','investor','realtor','developer','partner','other'], true)
            ? $value : 'other';
    }

    private function dealType(string $value): string
    {
        return in_array($value = strtolower(trim($value)), ['sale','rent','investment','consultation'], true)
            ? $value : 'consultation';
    }

    private function requestIntent(string $value): string
    {
        return in_array($value = strtolower(trim($value)), ['presentation','viewing','similar_search','general_contact'], true)
            ? $value : 'general_contact';
    }
}
