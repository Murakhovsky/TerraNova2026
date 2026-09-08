<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class SalesOperationService
{
    public function __construct(
        private MessageGatewayInterface $gateway,
        private SalesOperationRepositoryInterface $operations,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function sendMessage(
        SendMessageCommand $command,
        array $metadata = [],
        string $actorType = 'SYSTEM',
        string $actorId = 'system',
    ): OperationResult {
        $sent = $this->gateway->send($command);
        if (!$sent->successful) {
            return $sent;
        }

        return $this->transactions->transactional(function () use ($command, $metadata, $actorType, $actorId, $sent): OperationResult {
            $externalId = $sent->externalId ?? $command->idempotencyKey;
            $communicationId = $this->operations->recordOutboundCommunication(
                $command->organizationId,
                $command->dealReference,
                $command->channel,
                $command->body,
                $externalId,
                $command->idempotencyKey,
                $metadata,
            );
            if ($communicationId === null) {
                return OperationResult::success($externalId, ['duplicate' => true]);
            }

            $correlationId = substr(hash('sha256', $command->idempotencyKey), 0, 32);
            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),
                $command->organizationId,
                SalesEventType::MESSAGE_SENT,
                'deal',
                $command->dealReference,
                [
                    'communication_id' => $communicationId,
                    'external_id' => $externalId,
                    'channel' => $command->channel,
                    'purpose' => $metadata['purpose'] ?? 'sales_message',
                ],
                new EventMetadata($correlationId, null, $actorType, $actorId),
                new DateTimeImmutable(),
            ));

            return OperationResult::success($externalId, ['communication_id' => $communicationId]);
        });
    }

    public function scheduleMeeting(
        string $organizationId,
        string $dealId,
        string $title,
        DateTimeImmutable $at,
        string $idempotencyKey,
        array $metadata = [],
    ): OperationResult {
        return $this->transactions->transactional(function () use ($organizationId, $dealId, $title, $at, $idempotencyKey, $metadata): OperationResult {
            $id = $this->operations->scheduleMeeting(
                $organizationId,
                $dealId,
                $title,
                $at,
                $idempotencyKey,
                $metadata,
            );

            return OperationResult::success($id, [
                'duplicate' => $id === null,
                'scheduled_at' => $at->format(DATE_ATOM),
            ]);
        });
    }

    public function recordIncomingMessage(
        string $organizationId,
        string $dealId,
        string $channel,
        string $sender,
        string $recipient,
        string $body,
        string $externalId,
        string $correlationId,
        array $metadata = [],
    ): OperationResult {
        if (trim($externalId) === '' || trim($body) === '') {
            return OperationResult::failure('externalId and body are required.');
        }

        return $this->transactions->transactional(function () use (
            $organizationId,
            $dealId,
            $channel,
            $sender,
            $recipient,
            $body,
            $externalId,
            $correlationId,
            $metadata,
        ): OperationResult {
            $id = $this->operations->recordInboundCommunication(
                $organizationId,
                $dealId,
                $channel,
                $sender,
                $recipient,
                $body,
                $externalId,
                $metadata,
            );
            if ($id === null) {
                return OperationResult::success(null, ['duplicate' => true]);
            }

            $this->events->publish(new DomainEvent(
                bin2hex(random_bytes(16)),
                $organizationId,
                SalesEventType::MESSAGE_RECEIVED,
                'deal',
                $dealId,
                [
                    'communication_id' => $id,
                    'channel' => strtoupper($channel),
                    'external_id' => $externalId,
                ],
                new EventMetadata($correlationId, null, 'INTEGRATION', $channel),
                new DateTimeImmutable(),
            ));

            return OperationResult::success($id, ['duplicate' => false]);
        });
    }
}
