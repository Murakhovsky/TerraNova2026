<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Domains\Sales\Application\DTO\ClientCaseCommandResult;
use Domains\Sales\Application\Support\ClientCaseEvents;
use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Application\Support\ClientCasePeople;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Domains\Sales\Automation\Event\LeadChanged;
use Kernel\Event\EventBus;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class CreateClientCaseFromInboundRequest
{
    public function __construct(
        private ClientCaseCommandRepositoryInterface $commands,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $requestId, array $input = [], ?array $user = null): ClientCaseCommandResult
    {
        $request = $this->commands->inboundRequest($this->organizationId, $requestId);
        if (!$request) return ClientCaseCommandResult::failure('request_not_found', ['case_id' => null]);
        if (!empty($request['client_case_id'])) {
            return ClientCaseCommandResult::success('already_attached', ['case_id' => (int) $request['client_case_id']]);
        }

        $name = ClientCaseInput::limit((string) $request['full_name'], 160);
        $phone = ClientCaseInput::nullable((string) ($request['phone'] ?? ''), 50);
        $email = ClientCaseInput::email((string) ($request['email'] ?? ''));
        $managerId = $this->commands->activeManagerId(
            $this->organizationId, $input['assigned_user_id'] ?? ($user['id'] ?? null),
        );

        return $this->transactions->transactional(function () use (
            $requestId, $request, $input, $user, $name, $phone, $email, $managerId,
        ): ClientCaseCommandResult {
            $personId = ClientCasePeople::findOrCreate($this->commands, $this->organizationId, [
                'full_name' => $name, 'phone' => $phone, 'email' => $email, 'telegram' => null, 'notes' => null,
            ]);
            $caseInput = [
                'full_name' => $name,
                'type' => ClientCaseInput::caseTypeFromInbound($request),
                'title' => ClientCaseInput::caseTitleFromInbound($request, $name),
                'stage' => 'qualification', 'status' => 'active',
                'priority' => ClientCaseInput::allowed((string) ($input['priority'] ?? 'normal'), ClientCaseInput::PRIORITIES, 'normal'),
                'source' => ClientCaseInput::nullable((string) ($request['source_page'] ?? 'inbound-request'), 120),
                'description' => ClientCaseInput::text((string) ($request['message'] ?? '')),
                'currency' => 'USD',
            ];
            $case = ClientCaseInput::caseData(
                $caseInput,
                $name,
                $managerId,
                $this->commands->activePropertyTypeId($request['property_type_id'] ?? null),
                $this->commands->activeLocationId($request['location_id'] ?? null),
            );
            $caseId = $this->commands->createCase($this->organizationId, $personId, $case);
            if (!$this->commands->attachInboundRequest($this->organizationId, $caseId, $personId, $requestId, $managerId)
                || !$this->commands->registerInboundRequest($this->organizationId, $caseId, $requestId)
            ) {
                throw new RuntimeException('Inbound request could not be linked to the new client case.');
            }
            $this->commands->addActivity($this->organizationId, $caseId, $personId, $user['id'] ?? null, [
                'activity_type' => 'note', 'title' => 'Кейс створено із заявки',
                'body' => ClientCaseInput::text((string) ($request['message'] ?? '')), 'due_at' => null, 'completed_at' => null,
            ]);
            $propertyId = (int) ($request['property_id'] ?? 0);
            $property = $propertyId > 0 ? $this->commands->property($propertyId) : null;
            if ($property) {
                $this->commands->upsertPropertyMatch($this->organizationId, $caseId, $propertyId, [
                    'match_status' => 'interested', 'score' => null, 'note' => 'Обʼєкт із вхідної заявки',
                ]);
                $this->commands->addActivity($this->organizationId, $caseId, $personId, $user['id'] ?? null, [
                    'activity_type' => 'note', 'title' => 'Обʼєкт додано у підбір',
                    'body' => trim($property['public_id'] . ' / ' . $property['title'] . ' / Обʼєкт із вхідної заявки'),
                    'due_at' => null, 'completed_at' => null,
                ]);
            }

            $metadata = ClientCaseEvents::metadata($user);
            $this->events->publish(ClientCaseCreated::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $caseId,
                ['person_id' => $personId, 'stage' => 'qualification', 'source' => 'inbound-request'], $metadata,
            ));
            $this->events->publish(LeadChanged::create(
                ClientCaseEvents::id(), $this->organizationId, (string) $requestId,
                ['client_case_id' => ['from' => null, 'to' => $caseId], 'status' => ['from' => $request['status'], 'to' => 'qualified']],
                $metadata,
            ));
            return ClientCaseCommandResult::success('created', ['case_id' => $caseId, 'person_id' => $personId]);
        });
    }
}
