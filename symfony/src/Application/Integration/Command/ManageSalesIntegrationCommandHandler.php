<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\IntegrationMutationAudit;
use DomainException;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class ManageSalesIntegrationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SalesIntegrationAdministrationInterface $integrations,
        private SalesMutationReceiptRepositoryInterface $receipts,
        private TransactionManagerInterface $transactions,
        private IntegrationMutationAudit $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(ManageSalesIntegrationCommand $command): array
    {
        $organizationId = $command->organizationId->value();
        $key = trim($command->idempotencyKey);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('A valid idempotency key is required.');
        }
        if ($command->actorId <= 0) {
            throw new DomainException('Authenticated actor is invalid.');
        }

        $operationType = match ($command->action) {
            ManageSalesIntegrationCommand::CREATE => 'api.integration.create',
            ManageSalesIntegrationCommand::UPDATE => 'api.integration.update',
            ManageSalesIntegrationCommand::TEST => 'api.integration.test',
            ManageSalesIntegrationCommand::SAVE_ROUTE => 'api.integration.route',
            default => throw new DomainException('Unsupported integration mutation.'),
        };

        $payloadHash = $this->fingerprint([
            'action' => $command->action,
            'integration_id' => $command->integrationId,
            'input' => $command->input,
        ]);

        $existing = $this->receipts->find($organizationId, $operationType, $key);
        if ($existing !== null) {
            return $this->replay($existing, $payloadHash);
        }

        $pending = 'pending:' . $payloadHash . ':' . bin2hex(random_bytes(8));

        return $this->transactions->transactional(function () use (
            $command,
            $organizationId,
            $operationType,
            $key,
            $payloadHash,
            $pending,
        ): array {
            if (!$this->receipts->claim($organizationId, $operationType, $key, $pending)) {
                $existing = $this->receipts->find($organizationId, $operationType, $key);
                if ($existing === null) {
                    throw new RuntimeException('Integration mutation receipt could not be claimed.');
                }
                return $this->replay($existing, $payloadHash);
            }

            $result = $this->executeMutation($command, $organizationId);
            $reference = $this->mutationReference($command, $result);
            $completed = 'done:' . $payloadHash . ':' . $reference;

            if (!$this->receipts->complete($organizationId, $operationType, $key, $pending, $completed)) {
                throw new RuntimeException('Integration mutation receipt could not be completed.');
            }

            $this->audit->user(
                $organizationId,
                $command->actorId,
                $command->correlationId,
                'sales.integration.' . $command->action,
                $reference,
                [
                    'integration_id' => $command->integrationId ?? ($result['id'] ?? null),
                    'configuration_version' => $result['configuration_version'] ?? null,
                    'status' => $result['status'] ?? null,
                ],
            );

            return ['replayed' => false, ...$result];
        });
    }

    /** @return array<string,mixed> */
    private function executeMutation(ManageSalesIntegrationCommand $command, string $organizationId): array
    {
        return match ($command->action) {
            ManageSalesIntegrationCommand::CREATE => $this->integrations->create(
                $organizationId,
                $command->input,
                (string) $command->actorId,
            ),
            ManageSalesIntegrationCommand::UPDATE => $this->integrations->update(
                $organizationId,
                $this->integrationId($command),
                $command->input,
                (int) ($command->input['configuration_version'] ?? 0),
                (string) $command->actorId,
            ),
            ManageSalesIntegrationCommand::TEST => $this->integrations->testConnection(
                $organizationId,
                $this->integrationId($command),
            ),
            ManageSalesIntegrationCommand::SAVE_ROUTE => $this->integrations->saveRoute(
                $organizationId,
                $this->integrationId($command),
                $command->input,
                (string) $command->actorId,
            ),
            default => throw new DomainException('Unsupported integration mutation.'),
        };
    }

    private function integrationId(ManageSalesIntegrationCommand $command): int
    {
        if ($command->integrationId === null || $command->integrationId <= 0) {
            throw new DomainException('Invalid integration id.');
        }
        return $command->integrationId;
    }

    /** @param array<string,mixed> $result */
    private function mutationReference(ManageSalesIntegrationCommand $command, array $result): string
    {
        $reference = (string) ($result['id'] ?? $command->integrationId ?? '');
        if ($reference === '') {
            $reference = $command->action . ':' . ($command->integrationId ?? 'new');
        }
        return str_replace(':', '_', $reference);
    }

    /** @return array<string,mixed> */
    private function replay(string $receipt, string $payloadHash): array
    {
        $parts = explode(':', $receipt, 3);
        if (count($parts) < 2 || !hash_equals($payloadHash, $parts[1])) {
            throw new DomainException('Idempotency key was reused with a different integration mutation payload.');
        }
        if ($parts[0] === 'pending') {
            throw new DomainException('Integration mutation with this idempotency key is already in progress.');
        }
        if ($parts[0] !== 'done') {
            throw new DomainException('Idempotency receipt is invalid for this integration mutation.');
        }

        return [
            'replayed' => true,
            'mutation_reference' => $parts[2] ?? null,
        ];
    }

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
