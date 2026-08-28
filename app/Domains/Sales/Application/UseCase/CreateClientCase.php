<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Application\Support\ClientCasePeople;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class CreateClientCase
{
    public function __construct(
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(array $input, ?array $user = null): ClientCaseCommandResult
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
            $this->events->publish(ClientCaseCreated::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                ['person_id' => $personId, 'stage' => $case['stage']], ClientCaseEvents::metadata($user),
            ));
            return ClientCaseCommandResult::success('created', ['case_id' => $caseId, 'person_id' => $personId]);
        });
    }
}
