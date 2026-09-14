<?php
declare(strict_types=1);

namespace Domains\Property\Model;

use InvalidArgumentException;

final readonly class PropertyIdentity
{
    public function __construct(
        public string $organizationId,
        public string $assetId,
    ) {
        if (trim($this->organizationId) === '' || mb_strlen($this->organizationId) > 64) {
            throw new InvalidArgumentException('PropertyIdentity organization id is required and must fit the tenant key.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,79}$/', $this->assetId)) {
            throw new InvalidArgumentException('PropertyIdentity asset id must be a stable canonical identifier.');
        }
    }

    public static function fromAsset(PropertyAsset $asset): self
    {
        return new self($asset->organizationId, $asset->assetId);
    }

    public function equals(self $other): bool
    {
        return $this->organizationId === $other->organizationId && $this->assetId === $other->assetId;
    }

    public function key(): string
    {
        return $this->organizationId . ':' . $this->assetId;
    }
}
