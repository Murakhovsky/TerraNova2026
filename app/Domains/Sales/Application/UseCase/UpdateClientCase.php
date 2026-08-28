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
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class UpdateClientCase
{
    public function __construct(
        private ClientCaseReadModelInterface $readModel,
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $caseId, array $input, ?array $user = null): ClientCaseCommandResult
    {
        $existing = $this->readModel->case($caseId);
        if (!$existing) return ClientCaseCommandResult::failure('not_found');

        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? $existing['full_name'] ?? ''), 160);
        $managerId = $this->commands->activeManagerId(
            $this->organizationId,
            $input['assigned_user_id'] ?? ($existing['assigned_user_id'] ?? null),
        );
        $case = ClientCaseInput::caseData(
            $input,
            $name,
            $managerId,
            $this->commands->activePropertyTypeId($input['property_type_id'] ?? ($existing['property_type_id'] ?? null)),
            $this->commands->activeLocationId($input['location_id'] ?? ($existing['location_id'] ?? null)),
            $existing,
        );
        $correlationId = ClientCaseEvents::id();

        return $this->transactions->transactional(function () use ($caseId, $input, $user, $existing, $name, $case, $correlationId): ClientCaseCommandResult {
            if (!$this->commands->updatePerson($this->organizationId, (int) $existing['person_id'], [
                'full_name' => $name,
                'phone' => ClientCaseInput::nullable((string) ($input['phone'] ?? $existing['phone'] ?? ''), 50),
                'email' => ClientCaseInput::email((string) ($input['email'] ?? $existing['email'] ?? '')),
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? $existing['telegram'] ?? ''), 80),
                'notes' => ClientCaseInput::text((string) ($input['person_notes'] ?? $existing['person_notes'] ?? '')),
            ])) {
                throw new RuntimeException('Client person disappeared during update.');
            }
            if (!$this->commands->updateCase($this->organizationId, $caseId, $case)) {
                throw new RuntimeException('Client case disappeared during update.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, (int) $existing['person_id'], $user['id'] ?? null, [
                'activity_type' => 'status_change', 'title' => 'Кейс оновлено',
                'body' => 'Оновлено дані людини або параметри кейсу.', 'due_at' => null, 'completed_at' => null,
            ]);
            $metadata = ClientCaseEvents::metadata($user, $correlationId);
            $changes = [
                'stage' => ['from' => (string) $existing['stage'], 'to' => $case['stage']],
                'status' => ['from' => (string) $existing['status'], 'to' => $case['status']],
            ];
            $this->events->publish(ClientCaseChanged::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId, $changes, $metadata,
            ));
            if ((string) $existing['stage'] !== $case['stage']) {
                $this->events->publish(DealStageChanged::create(
                    ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                    (string) $existing['stage'], $case['stage'], $metadata,
                ));
            }
            return ClientCaseCommandResult::success('updated', ['case_id' => $caseId]);
        });
    }
}
