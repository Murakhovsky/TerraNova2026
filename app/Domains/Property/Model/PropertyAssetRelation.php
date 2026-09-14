<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PropertyAssetRelation
{
    public function __construct(
        public string $organizationId,
        public string $sourceAssetId,
        public string $targetAssetId,
        public PropertyAssetRelationType $type,
        public int $sortOrder = 0,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validTo = null,
    ) {
        if (trim($this->organizationId) === '') {
            throw new InvalidArgumentException('PropertyAssetRelation organization id is required.');
        }
        foreach ([$this->sourceAssetId, $this->targetAssetId] as $assetId) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $assetId)) {
                throw new InvalidArgumentException('PropertyAssetRelation requires canonical asset identifiers.');
            }
        }
        if ($this->sourceAssetId === $this->targetAssetId) {
            throw new InvalidArgumentException('PropertyAssetRelation cannot point an asset to itself.');
        }
        if ($this->sortOrder < 0) {
            throw new InvalidArgumentException('PropertyAssetRelation sort order cannot be negative.');
        }
        if ($this->validFrom !== null && $this->validTo !== null && $this->validTo <= $this->validFrom) {
            throw new InvalidArgumentException('PropertyAssetRelation validity interval is invalid.');
        }
    }
}
