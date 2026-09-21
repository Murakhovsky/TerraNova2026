<?php

declare(strict_types=1);

namespace App\Web\Experience\Async;

use App\Web\Experience\Extension\Contract\ActivityProviderInterface;
use App\Web\Experience\Extension\Contract\NotificationProviderInterface;
use App\Web\Experience\Extension\Model\ActivityItem;
use App\Web\Experience\Extension\Model\NotificationItem;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use Kernel\Queue\AsyncOperationProjection;
use Kernel\Queue\AsyncOperationStatus;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;

final readonly class AsyncOperationWebProvider implements ActivityProviderInterface, NotificationProviderInterface
{
    public function __construct(private AsyncOperationReadModelInterface $operations)
    {
    }

    public function serviceId(): string
    {
        return 'platformAsyncOperationProvider';
    }

    public function activities(WebExtensionContext $context, int $limit = 20): array
    {
        $items = [];

        foreach ($this->operations->recentForOrganization($context->organizationId, $limit) as $operation) {
            $items[] = new ActivityItem(
                id: 'async.operation.' . $operation->id,
                label: $this->label($operation),
                status: $operation->status->value,
                path: '/workspace/activity-center?operation=' . rawurlencode($operation->id),
                detail: $this->detail($operation),
                occurredAt: $operation->updatedAt,
                progress: $operation->progress,
                correlationId: $operation->correlationId,
                entity: $this->entity($operation),
                retryable: $operation->canRetry,
            );
        }

        return $items;
    }

    public function notifications(WebExtensionContext $context, int $limit = 20): array
    {
        $items = [];

        foreach ($this->operations->recentForOrganization($context->organizationId, max($limit * 3, 50)) as $operation) {
            if ($operation->status !== AsyncOperationStatus::Failed) {
                continue;
            }

            $items[] = new NotificationItem(
                id: 'async.operation.failure.' . $operation->id,
                title: $operation->canRetry
                    ? sprintf('%s failed', $this->humanize($operation->type))
                    : sprintf('%s will retry', $this->humanize($operation->type)),
                body: $operation->error,
                severity: $operation->canRetry ? 'danger' : 'warning',
                path: '/workspace/activity-center?operation=' . rawurlencode($operation->id),
                occurredAt: $operation->updatedAt,
                resourceId: $operation->id,
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function label(AsyncOperationProjection $operation): string
    {
        return sprintf('%s · %s', $this->humanize($operation->type), ucfirst($operation->status->value));
    }

    private function detail(AsyncOperationProjection $operation): string
    {
        if ($operation->error !== null) {
            return mb_substr($operation->error, 0, 220);
        }

        if ($operation->retryScheduled && $operation->availableAt !== null) {
            return sprintf('Retry scheduled for %s', $operation->availableAt->format('Y-m-d H:i:s'));
        }

        if ($operation->progress !== null) {
            return sprintf('%d%% complete', $operation->progress);
        }

        return sprintf(
            'Attempt %d/%d · correlation %s',
            $operation->attempts,
            $operation->maxAttempts,
            $operation->correlationId,
        );
    }

    private function entity(AsyncOperationProjection $operation): ?EntityRef
    {
        if ($operation->entityType === null || $operation->entityId === null) {
            return null;
        }

        try {
            return new EntityRef(strtolower($operation->entityType), $operation->entityId);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function humanize(string $type): string
    {
        $label = strtolower(str_replace(['.', '_', '-'], ' ', $type));
        $label = preg_replace('/\s+/', ' ', $label) ?: $label;

        return ucwords(trim($label));
    }
}
