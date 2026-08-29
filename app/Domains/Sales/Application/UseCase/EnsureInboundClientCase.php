<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Application\Support\ClientCasePeople;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\PipelineStage;
use Domains\Sales\Model\SalesCurrency;
use Domains\Sales\Model\SalesPriority;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class EnsureInboundClientCase
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
        $name = ClientCaseInput::limit((string) ($input['full_name'] ?? $input['name'] ?? ''), 160);
        $phone = ClientCaseInput::nullable((string) ($input['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === null && $email === null)) {
            return ClientCaseCommandResult::failure('contact_required', ['person_id' => null, 'client_case_id' => null]);
        }

        return $this->transactions->transactional(function () use ($input, $user, $name, $phone, $email): ClientCaseCommandResult {
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, [
                'full_name' => $name, 'phone' => $phone, 'email' => $email,
                'telegram' => ClientCaseInput::nullable((string) ($input['telegram'] ?? ''), 80), 'notes' => null,
            ]);
            $caseInput = [
                'full_name' => $name,
                'type' => ClientCaseInput::caseTypeFromInbound($input),
                'title' => ClientCaseInput::caseTitleFromInbound($input, $name),
                'stage' => PipelineStage::New->value,
                'status' => ClientCaseStatus::Active->value,
                'priority' => SalesPriority::Normal->value,
                'source' => 'site-inbound-request',
                'description' => ClientCaseInput::text((string) ($input['message'] ?? $input['comment'] ?? '')),
                'currency' => SalesCurrency::Usd->value,
            ];
            $case = ClientCaseInput::caseData($caseInput, $name, null, null, null);
            $caseId = $this->commands->createCase($this->organizationId, $personId, $case);
            $this->events->publish(ClientCaseCreated::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                ['person_id' => $personId, 'stage' => PipelineStage::New->value, 'source' => 'site-inbound-request'],
                ClientCaseEvents::metadata($user),
            ));
            return ClientCaseCommandResult::success('created', ['person_id' => $personId, 'client_case_id' => $caseId]);
        });
    }
}
