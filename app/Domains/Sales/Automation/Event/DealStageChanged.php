<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DealStageChanged
{
    public const TYPE = 'sales.deal.stage_changed';

    public static function create(
        string $id,
        string $organizationId,
        string $dealId,
        string $previousStage,
        string $newStage,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'deal',
            $dealId,
            ['previous_stage' => $previousStage, 'new_stage' => $newStage],
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
