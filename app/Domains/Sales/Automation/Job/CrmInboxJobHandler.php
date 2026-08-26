<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Job;

use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Application\UseCase\ReceiveCrmWebhook;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;
use RuntimeException;

final readonly class CrmInboxJobHandler implements JobHandlerInterface
{
    public function __construct(private ProcessCrmInbox $processor)
    {
    }

    public function supports(string $type): bool
    {
        return $type === ReceiveCrmWebhook::JOB_TYPE;
    }

    public function handle(Job $job): void
    {
        $id = trim((string) ($job->payload['inbox_id'] ?? ''));
        if ($id === '') throw new RuntimeException('CRM inbox job requires inbox_id.');
        $this->processor->execute($job->organizationId, $id, $job->claimedBy ?: 'queue-worker');
    }
}
