<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

final readonly class GrowthExternalEngagementDelivery
{
    public function __construct(
        public string $deliveryId,
        public string $status,
        public ?string $providerReference,
    ) {}
}
