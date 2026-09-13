<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DealCreated
{
    public const TYPE = 'sales.deal.created';

    public static function create(
        string $id,
        string $organizationId,
        string $dealId,
        string $pipelineId,
        string $stageId,
        string $stageCode,
        EventMetadata $metadata,
        ?DateTimeImmutable $occurredAt = null,
        ?int $assignedUserId = null,
    ): DomainEvent {
        $payload = [
            'pipeline_id' => $pipelineId,
            'stage_id' => $stageId,
            'stage_code' => $stageCode,
        ];
        if ($assignedUserId !== null && $assignedUserId > 0) {
            $payload['assigned_user_id'] = $assignedUserId;
        }

        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'deal',
            $dealId,
            $payload,
            $metadata,
            $occurredAt ?? new DateTimeImmutable(),
        );
    }
}
