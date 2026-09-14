<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DomainException;
use InvalidArgumentException;

final readonly class PropertyAsset
{
    public function __construct(
        public string $organizationId,
        public string $assetId,
        public PropertyType $type,
        public PropertyLocation $location,
        public PropertyLifecycle $lifecycle,
        public ?float $totalArea = null,
        public ?float $livingArea = null,
        public ?float $landArea = null,
        public ?float $rooms = null,
        public ?int $floor = null,
        public ?int $floors = null,
        public ?int $builtYear = null,
        public ?int $persistenceId = null,
    ) {
        $organizationId = trim($this->organizationId);
        if ($organizationId === '' || mb_strlen($organizationId) > 64) {
            throw new InvalidArgumentException('PropertyAsset organization id is required and must fit the tenant key.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->assetId)) {
            throw new InvalidArgumentException('PropertyAsset asset id must be a stable canonical identifier.');
        }
        if ($this->persistenceId !== null && $this->persistenceId <= 0) {
            throw new InvalidArgumentException('PropertyAsset persistence id must be positive when present.');
        }

        foreach ([
            'totalArea' => $this->totalArea,
            'livingArea' => $this->livingArea,
            'landArea' => $this->landArea,
            'rooms' => $this->rooms,
        ] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('PropertyAsset ' . $field . ' cannot be negative.');
            }
        }

        if ($this->totalArea !== null && $this->livingArea !== null && $this->livingArea > $this->totalArea) {
            throw new InvalidArgumentException('PropertyAsset living area cannot exceed total area.');
        }
        if ($this->floor !== null && $this->floor < 0) {
            throw new InvalidArgumentException('PropertyAsset floor cannot be negative.');
        }
        if ($this->floors !== null && $this->floors <= 0) {
            throw new InvalidArgumentException('PropertyAsset floors must be positive when present.');
        }
        if ($this->floor !== null && $this->floors !== null && $this->floor > $this->floors) {
            throw new InvalidArgumentException('PropertyAsset floor cannot exceed building floors.');
        }
        if ($this->builtYear !== null && ($this->builtYear < 1000 || $this->builtYear > 2200)) {
            throw new InvalidArgumentException('PropertyAsset built year is outside the supported physical range.');
        }
    }

    public function reclassify(PropertyType $type): self
    {
        return $this->copy(type: $type);
    }

    public function relocate(PropertyLocation $location): self
    {
        return $this->copy(location: $location);
    }

    public function changeLifecycle(PropertyLifecycle $lifecycle): self
    {
        if (!$this->lifecycle->canTransitionTo($lifecycle)) {
            throw new DomainException(sprintf(
                'PropertyAsset %s cannot transition lifecycle from %s to %s.',
                $this->assetId,
                $this->lifecycle->value,
                $lifecycle->value,
            ));
        }

        return $this->copy(lifecycle: $lifecycle);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'asset_id' => $this->assetId,
            'persistence_id' => $this->persistenceId,
            'type' => [
                'code' => $this->type->code,
                'reference_id' => $this->type->referenceId,
            ],
            'location' => $this->location->toArray(),
            'lifecycle' => $this->lifecycle->value,
            'physical' => [
                'total_area' => $this->totalArea,
                'living_area' => $this->livingArea,
                'land_area' => $this->landArea,
                'rooms' => $this->rooms,
                'floor' => $this->floor,
                'floors' => $this->floors,
                'built_year' => $this->builtYear,
            ],
        ];
    }

    private function copy(
        ?PropertyType $type = null,
        ?PropertyLocation $location = null,
        ?PropertyLifecycle $lifecycle = null,
    ): self {
        return new self(
            $this->organizationId,
            $this->assetId,
            $type ?? $this->type,
            $location ?? $this->location,
            $lifecycle ?? $this->lifecycle,
            $this->totalArea,
            $this->livingArea,
            $this->landArea,
            $this->rooms,
            $this->floor,
            $this->floors,
            $this->builtYear,
            $this->persistenceId,
        );
    }
}
