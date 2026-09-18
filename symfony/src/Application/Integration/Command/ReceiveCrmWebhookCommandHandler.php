<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\IntegrationMutationAudit;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\CrmIngressResolverInterface;
use Domains\Sales\Application\Contract\CrmWebhookSecretResolverInterface;
use InvalidArgumentException;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ReceiveCrmWebhookCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CrmIngressResolverInterface $ingress,
        private CrmWebhookSecretResolverInterface $secrets,
        private CrmInboxRepositoryInterface $inbox,
        private TransactionManagerInterface $transactions,
        private CommandBusInterface $commands,
        private IntegrationMutationAudit $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(ReceiveCrmWebhookCommand $command): array
    {
        if ($command->integrationId <= 0) {
            throw new InvalidArgumentException('Invalid CRM integration endpoint.');
        }

        $target = $this->ingress->resolve($command->integrationId);
        $organizationId = trim((string) ($target['organization_id'] ?? ''));
        $provider = strtolower(trim((string) ($target['provider'] ?? '')));
        $externalEventId = trim($command->externalEventId);
        $eventType = trim($command->eventType);

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $organizationId)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $provider)
            || !preg_match('/^[a-z][a-z0-9_.-]{1,158}$/', $eventType)
            || $externalEventId === ''
            || strlen($externalEventId) > 191
        ) {
            throw new InvalidArgumentException('Invalid CRM webhook envelope.');
        }

        if (strlen($command->rawPayload) > 1_048_576) {
            throw new InvalidArgumentException('CRM webhook payload is too large.');
        }

        $secret = $this->secrets->secretFor($organizationId, $provider);
        $expected = hash_hmac('sha256', $command->rawPayload, $secret);
        $provided = str_starts_with($command->signature, 'sha256=')
            ? substr($command->signature, 7)
            : $command->signature;

        if ($secret === '' || !hash_equals($expected, strtolower(trim($provided)))) {
            throw new InvalidArgumentException('Invalid CRM webhook signature.');
        }

        $inboxId = $this->transactions->transactional(fn (): string => $this->inbox->receive(
            $organizationId,
            $provider,
            $externalEventId,
            $eventType,
            $command->payload,
            $command->correlationId,
        ));

        // Dispatch after the inbox transaction commits. The periodic sweep is the
        // recovery path for the narrow DB-commit -> Redis-dispatch failure window.
        $this->commands->dispatch(new ProcessCrmInboxCommand(
            $organizationId,
            $inboxId,
            $command->correlationId,
        ));

        $this->audit->integration(
            $organizationId,
            $provider,
            $command->correlationId,
            'crm.webhook.accepted',
            $inboxId,
            [
                'integration_id' => $command->integrationId,
                'external_event_id' => $externalEventId,
                'event_type' => $eventType,
            ],
        );

        return [
            'inbox_id' => $inboxId,
            'organization_id' => $organizationId,
            'provider' => $provider,
        ];
    }
}
