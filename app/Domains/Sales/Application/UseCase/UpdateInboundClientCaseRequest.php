<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\LeadChanged;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class UpdateInboundClientCaseRequest
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $requestId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $request = $this->commands->inboundRequest($this->organizationId, $requestId);
        if (!$request) return ClientCaseCommandResult::failure('not_found', ['case_id' => null]);

        $status = ClientCaseInput::allowed(
            (string) ($input['status'] ?? $request['status']),
            ClientCaseInput::LEAD_STATUSES,
            (string) $request['status'],
        );
        $managerId = array_key_exists('assigned_user_id', $input)
            ? $this->commands->activeManagerId($this->organizationId, $input['assigned_user_id'])
            : (isset($request['assigned_user_id']) ? (int) $request['assigned_user_id'] : null);
        $managerNote = ClientCaseInput::text((string) ($input['manager_note'] ?? ($request['manager_note'] ?? '')));
        $nextContact = ClientCaseInput::dateTime((string) ($input['next_contact_at'] ?? ''));
        $contacted = ['contacted', 'qualified', 'viewing_planned', 'viewing', 'negotiation', 'won', 'lost'];
        $lastContacted = in_array($status, $contacted, true)
            ? (($request['last_contacted_at'] ?? null) ?: date('Y-m-d H:i:s'))
            : ($request['last_contacted_at'] ?? null);
        $activityType = ClientCaseInput::allowed(
            (string) ($input['activity_type'] ?? 'status_change'), ClientCaseInput::LEAD_ACTIVITY_TYPES, 'status_change',
        );
        $activityBody = ClientCaseInput::text((string) ($input['activity_body'] ?? '')) ?: $managerNote;
        $completedAt = !empty($input['completed']) ? date('Y-m-d H:i:s') : null;
        $caseId = (int) ($request['client_case_id'] ?? 0);
        $case = $caseId > 0 ? $this->readModel->case($caseId) : null;
        $correlationId = ClientCaseEvents::id();

        return $this->transactions->transactional(function () use (
            $requestId, $input, $user, $request, $status, $managerId, $managerNote, $nextContact,
            $lastContacted, $activityType, $activityBody, $completedAt, $caseId, $case, $correlationId,
        ): ClientCaseCommandResult {
            if (!$this->commands->updateInboundRequest($this->organizationId, $requestId, [
                'status' => $status, 'assigned_user_id' => $managerId, 'manager_note' => $managerNote,
                'last_contacted_at' => $lastContacted, 'next_contact_at' => $nextContact,
            ])) {
                throw new RuntimeException('Inbound request disappeared during update.');
            }
            $this->commands->addLeadActivity($this->organizationId, $requestId, $user['id'] ?? null, [
                'activity_type' => $activityType,
                'title' => ClientCaseInput::limit((string) ($input['activity_title'] ?? 'Заявку оновлено'), 180),
                'body' => $activityBody, 'due_at' => $nextContact, 'completed_at' => $completedAt,
            ]);

            $metadata = ClientCaseEvents::metadata($user, $correlationId);
            $this->events->publish(LeadChanged::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $requestId,
                ['status' => ['from' => $request['status'], 'to' => $status], 'assigned_user_id' => ['from' => $request['assigned_user_id'] ?? null, 'to' => $managerId]],
                $metadata,
            ));

            if ($case) {
                $state = ClientCaseInput::caseStateForLead($status);
                if (!$this->commands->syncCaseFromLead($this->organizationId, $caseId, [
                    'stage' => $state['stage'], 'status' => $state['status'], 'assigned_user_id' => $managerId,
                    'next_contact_at' => $nextContact,
                    'closed_at' => in_array($state['status'], ['closed', 'lost'], true) ? date('Y-m-d H:i:s') : null,
                ])) {
                    throw new RuntimeException('Linked client case disappeared during lead synchronization.');
                }
                $this->commands->addActivity($this->organizationId, $caseId, (int) $case['person_id'], $user['id'] ?? null, [
                    'activity_type' => $activityType === 'viewing' ? 'viewing' : 'note',
                    'title' => 'Заявку оновлено: ' . ClientCaseInput::leadStatusLabel($status),
                    'body' => $activityBody, 'due_at' => $nextContact, 'completed_at' => $completedAt,
                ]);
                if ($completedAt !== null) $this->commands->clearNextContact($this->organizationId, $caseId);
                $changes = [
                    'stage' => ['from' => (string) $case['stage'], 'to' => $state['stage']],
                    'status' => ['from' => (string) $case['status'], 'to' => $state['status']],
                ];
                $this->events->publish(ClientCaseChanged::create(
                    ClientCaseEvents::id(), $this->organizationId, (string) $caseId, $changes, $metadata,
                ));
                if ((string) $case['stage'] !== $state['stage']) {
                    $this->events->publish(DealStageChanged::create(
                        ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                        (string) $case['stage'], $state['stage'], $metadata,
                    ));
                }
            }
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }
}
