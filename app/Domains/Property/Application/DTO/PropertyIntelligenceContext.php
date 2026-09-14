<?php
declare(strict_types=1);

namespace Domains\Property\Application\DTO;

use InvalidArgumentException;

final readonly class PropertyIntelligenceContext
{
    public function __construct(
        public string $organizationId,
        public string $assetId,
        public ?string $inventoryId,
        public array $facts,
        public array $comparables,
        public array $marketSignals,
        public string $methodologyVersion = '0.9.0',
        public ?string $correlationId = null,
    ) {
        if (trim($organizationId) === '' || trim($assetId) === '') {
            throw new InvalidArgumentException('Property intelligence requires organization and asset identity.');
        }
    }

    /** @return array<string,mixed> */
    public function evidence(): array
    {
        return [
            'asset_id' => $this->assetId,
            'inventory_id' => $this->inventoryId,
            'facts' => $this->facts,
            'comparables' => $this->comparables,
            'market_signals' => $this->marketSignals,
            'methodology_version' => $this->methodologyVersion,
        ];
    }
}
