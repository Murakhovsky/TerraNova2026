<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\CrmWebhookSecretResolverInterface;
use InvalidArgumentException;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ReceiveCrmWebhook
{
    public const JOB_TYPE = 'CRM_INBOX_PROCESS';

    public function __construct(
        private CrmWebhookSecretResolverInterface $secrets,
        private CrmInboxRepositoryInterface $inbox,
        private JobQueueInterface $queue,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(
        string $organizationId,
        string $provider,
        string $externalEventId,
        string $eventType,
        array $payload,
        string $signature,
        ?string $rawPayload = null,
    ): string {
        $organizationId = trim($organizationId);
        $provider = strtolower(trim($provider));
        $externalEventId = trim($externalEventId);
        $eventType = trim($eventType);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $organizationId)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $provider)
            || !preg_match('/^[a-z][a-z0-9_.-]{1,158}$/', $eventType)
            || $externalEventId === ''
            || strlen($externalEventId) > 191
        ) {
            throw new InvalidArgumentException('Invalid CRM webhook envelope.');
        }
        $secret = $this->secrets->secretFor($organizationId, $provider);
        $canonical = $rawPayload !== null && $rawPayload !== ''
            ? $rawPayload
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($canonical) > 1_048_576) {
            throw new InvalidArgumentException('CRM webhook payload is too large.');
        }
        $expected = hash_hmac('sha256', $canonical, $secret);
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        if ($secret === '' || !hash_equals($expected, strtolower(trim($provided)))) {
            throw new InvalidArgumentException('Invalid CRM webhook signature.');
        }
        return $this->transactions->transactional(function () use (
            $organizationId, $provider, $externalEventId, $eventType, $payload
        ): string {
            $correlationId = bin2hex(random_bytes(16));
            $id = $this->inbox->receive(
                $organizationId,
                $provider,
                $externalEventId,
                $eventType,
                $payload,
                $correlationId,
            );
            $this->queue->enqueue(
                $organizationId,
                self::JOB_TYPE,
                ['inbox_id' => $id],
                $correlationId,
                'crm-inbox:' . $organizationId . ':' . $provider . ':' . $externalEventId,
                10,
                120,
            );
            return $id;
        });
    }
}
