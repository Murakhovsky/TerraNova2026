<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\IntegrationMutationAudit;
use DomainException;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Model\CommunicationChannel;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class SendSalesCommunicationCommandHandler implements CommandHandlerInterface
{
    private const OPERATION = 'api.communication.send';

    public function __construct(
        private SalesOperationService $operations,
        private SalesMutationReceiptRepositoryInterface $receipts,
        private TransactionManagerInterface $transactions,
        private IntegrationMutationAudit $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(SendSalesCommunicationCommand $command): array
    {
        if ($command->actorId <= 0 || $command->opportunityId <= 0) {
            throw new DomainException('Invalid communication actor or opportunity.');
        }

        $channel = strtoupper(trim($command->channel));
        if (!in_array($channel, CommunicationChannel::values(), true)) {
            throw new DomainException('Unsupported communication channel.');
        }

        $body = trim($command->body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new DomainException('Communication body is required and must not exceed 4000 characters.');
        }

        $key = trim($command->idempotencyKey);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('A valid idempotency key is required.');
        }

        $organizationId = $command->organizationId->value();
        $hash = hash('sha256', json_encode([
            'opportunity_id' => $command->opportunityId,
            'channel' => $channel,
            'body' => $body,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $existing = $this->receipts->find($organizationId, self::OPERATION, $key);
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        $pending = 'pending:' . $hash . ':' . bin2hex(random_bytes(8));

        return $this->transactions->transactional(function () use (
            $command,
            $organizationId,
            $channel,
            $body,
            $key,
            $hash,
            $pending,
        ): array {
            if (!$this->receipts->claim($organizationId, self::OPERATION, $key, $pending)) {
                $existing = $this->receipts->find($organizationId, self::OPERATION, $key);
                if ($existing === null) {
                    throw new RuntimeException('Communication receipt could not be claimed.');
                }
                return $this->replay($existing, $hash);
            }

            $result = $this->operations->sendMessage(
                new SendMessageCommand(
                    $organizationId,
                    (string) $command->opportunityId,
                    $channel,
                    $body,
                    $key,
                ),
                [
                    'surface' => 'symfony_v1',
                    'correlation_id' => $command->correlationId,
                ],
                'USER',
                (string) $command->actorId,
            );

            if (!$result->successful) {
                throw new DomainException($result->error ?? 'Communication delivery failed.');
            }

            $externalId = $result->externalId ?? 'none';
            if (!$this->receipts->complete(
                $organizationId,
                self::OPERATION,
                $key,
                $pending,
                'done:' . $hash . ':' . str_replace(':', '_', $externalId),
            )) {
                throw new RuntimeException('Communication receipt could not be completed.');
            }

            $this->audit->user(
                $organizationId,
                $command->actorId,
                $command->correlationId,
                'sales.communication.sent',
                (string) $command->opportunityId,
                [
                    'channel' => $channel,
                    'external_id' => $result->externalId,
                ],
            );

            return [
                'replayed' => false,
                'external_id' => $result->externalId,
                ...$result->data,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function replay(string $receipt, string $hash): array
    {
        $parts = explode(':', $receipt, 3);
        if (count($parts) < 2 || !hash_equals($hash, $parts[1])) {
            throw new DomainException('Idempotency key was reused with a different communication payload.');
        }
        if ($parts[0] === 'pending') {
            throw new DomainException('Communication with this idempotency key is already in progress.');
        }
        if ($parts[0] !== 'done') {
            throw new DomainException('Communication idempotency receipt is invalid.');
        }

        return [
            'replayed' => true,
            'external_id' => ($parts[2] ?? 'none') !== 'none' ? ($parts[2] ?? null) : null,
        ];
    }
}
