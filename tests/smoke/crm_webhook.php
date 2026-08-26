<?php
declare(strict_types=1);

use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\CrmWebhookSecretResolverInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Job;
use Kernel\Transaction\Contract\TransactionManagerInterface;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Kernel\\' => '/app/Kernel/', 'Domains\\' => '/app/Domains/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
        }
    }
});

$secret = 'unit-test-secret';
$secrets = new class($secret) implements CrmWebhookSecretResolverInterface {
    public function __construct(private string $secret) {}
    public function secretFor(string $organizationId, string $provider): string { return $this->secret; }
};
$inbox = new class implements CrmInboxRepositoryInterface {
    public array $items = [];
    public function receive(string $organizationId, string $provider, string $externalEventId, string $eventType, array $payload, string $correlationId): string {
        $key = $organizationId . ':' . $provider . ':' . $externalEventId;
        return $this->items[$key]['id'] ?? ($this->items[$key] = ['id' => 'inbox-' . (count($this->items) + 1), 'event' => $eventType])['id'];
    }
    public function claim(string $organizationId, string $id, string $workerId): ?CrmInboxItem { return null; }
    public function complete(CrmInboxItem $item): void {}
    public function fail(CrmInboxItem $item, Throwable $error): void {}
};
$queue = new class implements JobQueueInterface {
    public array $jobs = [];
    public function enqueue(string $organizationId, string $type, array $payload, string $correlationId, ?string $idempotencyKey = null, int $maxAttempts = 5, int $timeoutSeconds = 60): string {
        $key = $organizationId . ':' . $idempotencyKey;
        if (!isset($this->jobs[$key])) $this->jobs[$key] = ['id' => 'job-' . (count($this->jobs) + 1), 'type' => $type, 'payload' => $payload];
        return $this->jobs[$key]['id'];
    }
    public function claim(string $workerId): ?Job { return null; }
    public function complete(Job $job): void {}
    public function fail(Job $job, string $error): void {}
    public function recoverTimedOut(): int { return 0; }
    public function replayDead(?string $organizationId = null, ?string $jobId = null): int { return 0; }
};
$transactions = new class implements TransactionManagerInterface {
    public int $calls = 0;
    public function transactional(callable $operation): mixed { $this->calls++; return $operation(); }
    public function isActive(): bool { return false; }
    public function afterCommit(callable $callback): void { $callback(); }
};
$receiver = new ReceiveCrmWebhook($secrets, $inbox, $queue, $transactions);
$raw = '{"event_type":"deal.stage_changed","entity_type":"deal","external_id":"crm-42"}';
$payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
$signature = 'sha256=' . hash_hmac('sha256', $raw, $secret);

$first = $receiver->execute('tenant-a', 'hubspot', 'evt-1', $payload['event_type'], $payload, $signature, $raw);
$second = $receiver->execute('tenant-a', 'hubspot', 'evt-1', $payload['event_type'], $payload, $signature, $raw);
if ($first !== $second || count($inbox->items) !== 1 || count($queue->jobs) !== 1 || $transactions->calls !== 2) {
    throw new RuntimeException('CRM webhook idempotency or transaction boundary failed.');
}

try {
    $receiver->execute('tenant-a', 'hubspot', 'evt-2', $payload['event_type'], $payload, 'invalid', $raw);
    throw new RuntimeException('Invalid CRM signature was accepted.');
} catch (InvalidArgumentException) {
}
if (count($inbox->items) !== 1 || count($queue->jobs) !== 1) {
    throw new RuntimeException('Rejected CRM webhook changed durable state.');
}

echo "CRM webhook signature, atomic enqueue and idempotency passed.\n";
