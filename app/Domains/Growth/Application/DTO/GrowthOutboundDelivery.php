<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

final readonly class GrowthOutboundDelivery
{
    public function __construct(
        public string $notificationId,
        public string $deliveryId,
        public string $status,
        public ?string $providerMessageId,
    ) {}
}
