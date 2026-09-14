<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyIdentitySignals
{
    /** @param list<string> $externalReferenceKeys */
    public function __construct(
        public array $externalReferenceKeys = [],
        public ?string $cadastralNumber = null,
        public ?string $addressCanonicalKey = null,
        public ?string $developmentAssetId = null,
        public ?string $buildingAssetId = null,
        public ?string $unitLabel = null,
        public ?float $totalArea = null,
    ) {
        foreach ($this->externalReferenceKeys as $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('PropertyIdentitySignals external reference keys must be non-empty strings.');
            }
        }
        if ($this->totalArea !== null && $this->totalArea < 0) {
            throw new InvalidArgumentException('PropertyIdentitySignals total area cannot be negative.');
        }
    }

    /** @return list<string> */
    public function normalizedExternalReferenceKeys(): array
    {
        $keys = array_map(static fn (string $key): string => strtolower(trim($key)), $this->externalReferenceKeys);
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }
}
